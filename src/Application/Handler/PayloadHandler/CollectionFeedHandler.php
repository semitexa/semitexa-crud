<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Handler\PayloadHandler;

use Semitexa\Api\Application\Service\Collection\CollectionFeedSupport;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\CollectionQueryCompilerInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Application\Service\Feed\FeedDeclarations;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Crud\Domain\Contract\CollectionSourceInterface;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\OrmManager;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseCollectionFeedHandler;
use Semitexa\Ssr\Domain\Contract\SseCollectionFeedPayloadInterface;

/**
 * Serves every #[AsCollectionFeed] (any CollectionFeed subclass) — over its
 * ORM model, or by asking its `source` (CollectionSourceInterface): the
 * request's search, sort, filter and page — checked against what the fields
 * allow — compiled over the feed's model, scoped to the tenant when the model
 * is tenant-scoped, projected to rows by the feed's row(), and enveloped with
 * the filter options the fields declare. Live re-runs come through the same
 * method (AbstractSseCollectionFeedHandler::serve).
 */
#[AsPayloadHandler(payload: CollectionFeed::class, resource: CollectionFeedJsonResponse::class)]
final class CollectionFeedHandler extends AbstractSseCollectionFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CollectionFeedSupport $feedSupport;

    #[InjectAsReadonly]
    protected CollectionQueryCompilerInterface $compiler;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    #[InjectAsReadonly]
    protected RelationOptions $relations;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function handle(CollectionFeed $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        return $this->serve($payload, $response);
    }

    protected function buildCollectionResponse(
        SseCollectionFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        if (!$payload instanceof CollectionFeed || !$response instanceof CollectionFeedJsonResponse) {
            throw new \LogicException('CollectionFeedHandler serves CollectionFeed payloads with a CollectionFeedJsonResponse.');
        }

        $feed = $payload->declaration();
        // Relation fields get their choices: rows show labels, filters list them.
        $model = $feed->model();
        $declared = $payload->fields();
        $hasRelations = array_filter($declared, RelationOptions::isRelation(...)) !== [];
        $fields = new UiFieldSet($model !== null && $hasRelations ? $this->relations->resolve($model, $declared) : $declared);
        // An unset parameter is '' here and null on the wire; criteriaFrom()
        // trims both to "not requested".
        $view = $payload->toViewParams();
        $criteria = $this->feedSupport->criteriaFrom(
            FeedDeclarations::of($fields, $feed),
            rawQ:       $view['q'],
            rawSort:    $view['sort'],
            rawFilter:  $view['filter'],
            rawPage:    $view['page'],
            rawPerPage: $view['perPage'],
            rawCursor:  $view['cursor'],
        );

        $criteria = FeedDeclarations::normalizeRanges($criteria, $fields);
        $source = $feed->source();
        if ($source !== null) {
            $resolved = RequestScopedContainer::forCurrentExecution($this->container)->get($source);
            if (!$resolved instanceof CollectionSourceInterface) {
                throw new \LogicException(sprintf('%s is not a %s.', $source, CollectionSourceInterface::class));
            }
            $slice = $resolved->slice($criteria, $payload);

            return $response->withRows($slice->rows, $slice->page, $slice->cursorPage, FeedDeclarations::filterOptions($fields), $fields->idField());
        }

        $metadata = $this->orm->getResourceModelMetadataRegistry()->for((string) $model);
        $query = $this->orm->query((string) $model);
        if ($metadata->tenantPolicy !== null) {
            // A tenant-scoped model is read for the request's tenant only;
            // the ORM refuses an unscoped read of it anyway (fail-closed).
            $query = $query->forTenant(TenantContextAccess::tenantIdOrDefault($this->tenantContextStore->tryGet()));
        }
        $query = $payload->query($query);
        $counts = $this->relationCounts($query, $fields, $metadata, $payload);
        [$criteria, $query] = $this->filterThroughPivots($criteria, $query, $fields, $metadata);

        $compiled = $this->compiler->compile($criteria, $query, self::fieldMap($fields, $metadata));

        $rows = [];
        foreach ($compiled->items as $model) {
            $rows[] = $payload->row($model, $fields);
        }
        $rows = $this->withPivotLabels($rows, $compiled->items, $fields, $metadata);

        return $response->withRows($rows, $compiled->page, $compiled->cursorPage, FeedDeclarations::filterOptions($fields, $counts), $fields->idField());
    }

    /**
     * A filter on a belongsToMany field ("tags:eq:<id>") lives on the pivot,
     * which the SQL compiler cannot reach: take those terms out of the
     * criteria, find the matching record ids in the pivot, and narrow the
     * query to them. No match is an empty page, never an unfiltered one.
     *
     * @return array{0: CollectionCriteria, 1: ResourceModelQuery}
     */
    private function filterThroughPivots(CollectionCriteria $criteria, ResourceModelQuery $query, UiFieldSet $fields, ResourceModelMetadata $metadata): array
    {
        $remaining = [];
        $pk = $metadata->primaryKeyProperty ?? 'id';
        foreach ($criteria->filter->terms as $term) {
            $field = $fields->get($term->field);
            if ($field === null || $field->type !== 'belongsToMany') {
                $remaining[] = $term;
                continue;
            }
            $relation = $this->relations->relationOf($metadata, $field);
            $related = array_values(array_map('strval', (array) $term->value));
            $params = [];
            foreach ($related as $i => $value) {
                $params['related' . $i] = $value;
            }
            $result = $this->orm->getAdapter()->execute(
                sprintf(
                    'SELECT %s AS __id FROM %s WHERE %s IN (%s)',
                    SqlIdentifier::quote($relation->foreignKey),
                    SqlIdentifier::quote((string) $relation->pivotTable),
                    SqlIdentifier::quote((string) $relation->relatedKey),
                    implode(', ', array_map(static fn (string $name): string => ':' . $name, array_keys($params))),
                ),
                $params,
            );
            $ids = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['__id'], $result->rows)));
            $query = $query->whereIn(ColumnRef::for($metadata->className, $pk), $ids === [] ? ['__no_match__'] : $ids);
        }
        if (count($remaining) === count($criteria->filter->terms)) {
            return [$criteria, $query];
        }

        return [new CollectionCriteria(
            page: $criteria->page,
            sort: $criteria->sort,
            filter: new CollectionFilterRequest($remaining),
            q: $criteria->q,
            searchFields: $criteria->searchFields,
            cursor: $criteria->cursor,
            policy: $criteria->policy,
            pageWasRequested: $criteria->pageWasRequested,
        ), $query];
    }

    /**
     * How many of the feed's records each choice of a filterable relation
     * holds — over the records the feed serves (tenant and query() applied),
     * not narrowed by the filters chosen now, so the numbers say what picking
     * a choice would find. One GROUP BY per field.
     *
     * A many-to-many counts its pivot, which also holds rows of records the
     * feed does not serve when the model is tenant-scoped or query() narrows
     * it; those fields are left uncounted rather than miscounted.
     *
     * @return array<string, array<string, int>>
     */
    private function relationCounts(ResourceModelQuery $query, UiFieldSet $fields, ResourceModelMetadata $metadata, CollectionFeed $feed): array
    {
        $counts = [];
        $narrowed = $metadata->tenantPolicy !== null
            || (new \ReflectionMethod($feed, 'query'))->getDeclaringClass()->getName() !== CollectionFeed::class;
        foreach ($fields->all() as $field) {
            if (!$field->filterable || !RelationOptions::isRelation($field)) {
                continue;
            }
            $relation = $this->relations->relationOf($metadata, $field);
            if ($field->type === 'belongsTo') {
                $counts[$field->name] = array_map('intval', (clone $query)->countBy(ColumnRef::for($metadata->className, CrudRecords::property($field, $metadata))));
                continue;
            }
            if ($narrowed || $relation->pivotTable === null || $relation->relatedKey === null) {
                continue;
            }
            $result = $this->orm->getAdapter()->execute(sprintf(
                'SELECT %1$s AS __related, COUNT(*) AS __c FROM %2$s GROUP BY %1$s',
                SqlIdentifier::quote($relation->relatedKey),
                SqlIdentifier::quote($relation->pivotTable),
            ));
            foreach ($result->rows as $row) {
                $counts[$field->name][(string) $row['__related']] = (int) $row['__c'];
            }
        }

        return $counts;
    }

    /**
     * The listed many-to-many fields of this page of rows, as their choices'
     * labels: one pivot query per field for the whole page.
     *
     * @param list<array<string, mixed>> $rows
     * @param iterable<object>          $models the same rows, as models
     * @return list<array<string, mixed>>
     */
    private function withPivotLabels(array $rows, iterable $models, UiFieldSet $fields, ResourceModelMetadata $metadata): array
    {
        $pk = $metadata->primaryKeyProperty ?? 'id';
        $ids = [];
        foreach ($models as $model) {
            $ids[] = (string) $model->{$pk};
        }
        foreach ($fields->onList() as $field) {
            if ($field->type !== 'belongsToMany') {
                continue;
            }
            $labels = array_column($field->options, 'label', 'value');
            $related = $this->relations->pivotIds($this->relations->relationOf($metadata, $field), $ids);
            foreach ($rows as $i => $row) {
                $names = array_map(static fn (string $r): string => $labels[$r] ?? $r, $related[$ids[$i] ?? ''] ?? []);
                $rows[$i][$field->name] = $names === [] ? null : implode(', ', $names);
            }
        }

        return $rows;
    }

    /**
     * API field → model property, for every field the request may sort,
     * search or filter by: the field's "property" setting, else its own name,
     * else its snake_case twin.
     *
     * @return array<string, string>
     */
    private static function fieldMap(UiFieldSet $fields, ResourceModelMetadata $metadata): array
    {
        $map = [];
        foreach ($fields->all() as $field) {
            if ((!$field->sortable && !$field->searchable && !$field->filterable) || $field->type === 'belongsToMany') {
                continue;
            }
            $declared = (string) $field->setting('property', $field->name);
            $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $declared));
            $property = match (true) {
                $metadata->hasColumn($declared) => $declared,
                $metadata->hasColumn($snake) => $snake,
                default => throw new \LogicException(sprintf(
                    'Feed field "%s" maps to no column of %s (tried "%s" and "%s"); set its "property".',
                    $field->name,
                    $metadata->className,
                    $declared,
                    $snake,
                )),
            };
            $map[$field->name] = $property;
        }

        return $map;
    }
}
