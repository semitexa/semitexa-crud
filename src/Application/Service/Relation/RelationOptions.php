<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Relation;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * The choices of a model's relation fields, read from the related model:
 *
 *     Field::belongsTo('categoryId')->set('labelProperty', 'name')   // the model's #[BelongsTo] on category_id
 *     Field::belongsToMany('tags')                                   // the model's #[ManyToMany] $tags
 *
 * A field found no relation for fails loudly; one that already has options
 * (given by hand) is left alone. The related records are read for the
 * request's tenant when that model is tenant-scoped, ordered by their label,
 * at most MAX of them.
 *
 * Worker singleton: the tenant is read from the store on every call.
 */
#[AsService]
final class RelationOptions
{
    /** A select of more records than this is a search, not a list (a follow-up). */
    public const MAX = 500;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /**
     * @param class-string    $modelClass
     * @param list<UiField>   $fields
     * @return list<UiField> the same fields, relation ones with their options
     */
    public function resolve(string $modelClass, array $fields): array
    {
        $metadata = null;
        $resolved = [];
        foreach ($fields as $field) {
            if (!self::isRelation($field) || $field->options !== []) {
                $resolved[] = $field;
                continue;
            }
            $metadata ??= $this->orm->getResourceModelMetadataRegistry()->for($modelClass);
            $resolved[] = $field->options($this->options($this->relationOf($metadata, $field), (string) $field->setting('labelProperty', 'name')));
        }

        return $resolved;
    }

    /**
     * Relation values the field's current choices do not hold — a record
     * deleted since the form was drawn, or an id the form never offered.
     *
     * @param list<UiField>        $fields resolved (with options)
     * @param array<string, mixed> $values cast form values
     * @return array<string, string> field name → message
     */
    public static function unknownChoices(array $fields, array $values): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!self::isRelation($field) || !array_key_exists($field->name, $values) || $values[$field->name] === null) {
                continue;
            }
            $known = array_column($field->options, 'value');
            $given = is_array($values[$field->name]) ? $values[$field->name] : [$values[$field->name]];
            if (array_diff(array_map('strval', $given), $known) !== []) {
                $errors[$field->name] = 'Choose from the list: one of these no longer exists.';
            }
        }

        return $errors;
    }

    public static function isRelation(UiField $field): bool
    {
        return $field->type === 'belongsTo' || $field->type === 'belongsToMany';
    }

    /** The model's relation a relation field stands for. */
    public function relationOf(ResourceModelMetadata $metadata, UiField $field): RelationMetadata
    {
        $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $field->name));
        foreach ($metadata->relations() as $relation) {
            $matches = $field->type === 'belongsTo'
                ? $relation->kind === RelationKind::BelongsTo && in_array($relation->foreignKey, [$field->name, $snake], true)
                : $relation->kind === RelationKind::ManyToMany && in_array($relation->propertyName, [$field->name, $snake], true);
            if ($matches) {
                return $relation;
            }
        }

        throw new \LogicException(sprintf(
            '%s field "%s" has no relation in %s: a belongsTo names the #[BelongsTo] foreign key column, a belongsToMany the #[ManyToMany] property.',
            $field->type,
            $field->name,
            $metadata->className,
        ));
    }

    /**
     * The related ids of several records of a many-to-many relation, read from
     * its pivot in one query — whatever type the model gives the relation
     * property (eager loading needs RelationState; a plain array is common).
     *
     * @param list<string> $ownerIds
     * @return array<string, list<string>> owner id → related ids
     */
    public function pivotIds(RelationMetadata $relation, array $ownerIds): array
    {
        if ($ownerIds === [] || $relation->pivotTable === null || $relation->relatedKey === null) {
            return [];
        }
        $params = [];
        foreach (array_values($ownerIds) as $i => $id) {
            $params['owner' . $i] = $id;
        }
        $result = $this->orm->getAdapter()->execute(
            sprintf(
                'SELECT %1$s AS __owner, %2$s AS __related FROM %3$s WHERE %1$s IN (%4$s)',
                SqlIdentifier::quote($relation->foreignKey),
                SqlIdentifier::quote($relation->relatedKey),
                SqlIdentifier::quote($relation->pivotTable),
                implode(', ', array_map(static fn (string $name): string => ':' . $name, array_keys($params))),
            ),
            $params,
        );
        $out = [];
        foreach ($result->rows as $row) {
            $out[(string) $row['__owner']][] = (string) $row['__related'];
        }

        return $out;
    }

    /** @return array<string, array{label: string}> id → option (an array, so a numeric id stays a key, not a position) */
    private function options(RelationMetadata $relation, string $labelProperty): array
    {
        $target = $this->orm->getResourceModelMetadataRegistry()->for($relation->targetClass);
        $pk = $target->primaryKeyProperty
            ?? throw new \LogicException(sprintf('%s has no #[PrimaryKey]; a relation field needs one to name its choices.', $target->className));
        if (!$target->hasColumn($labelProperty)) {
            throw new \LogicException(sprintf('%s has no column "%s" to label its choices; set the field\'s "labelProperty".', $target->className, $labelProperty));
        }
        $query = $this->orm->query($target->className);
        if ($target->tenantPolicy !== null) {
            $query = $query->forTenant(TenantContextAccess::tenantIdOrDefault($this->tenantContextStore->tryGet()));
        }
        $options = [];
        foreach ($query->orderBy(ColumnRef::for($target->className, $labelProperty), Direction::Asc)->limit(self::MAX)->fetchAll() as $record) {
            $options[(string) $record->{$pk}] = ['label' => (string) $record->{$labelProperty}];
        }

        return $options;
    }
}
