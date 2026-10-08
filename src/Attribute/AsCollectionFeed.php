<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Contract\DeclaresWatchScopesInterface;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Domain\Contract\CollectionFeedRouteInterface;
use Semitexa\Orm\Domain\Model\ResourceMetadata;

/**
 * A live collection feed declared by its fields: the route, its contract
 * (`collection` + `ui`), its paging, search, sort and filters, and its live
 * watch scope (the model's resource key) all come from this attribute and the
 * class's fields(). The class extends CollectionFeed and writes no setters,
 * no field map and no projection.
 *
 *     #[AsCollectionFeed(path: '/admin/pings/feed', name: 'admin.pings.feed', model: PingResource::class)]
 *     final class PingsFeed extends CollectionFeed
 *     {
 *         public function fields(): array { return [Field::id(), Field::text('label')->searchable(), …]; }
 *     }
 */
#[Capability(
    id: 'crud.collection-feed',
    summary: 'A live, paginated, searchable, sortable, filterable collection feed for platform.grid, declared by a field list over an ORM model.',
    useWhen: 'A grid lists rows of one ORM model and should update live when they change.',
    avoidWhen: 'The rows are not one model (a report joining several) - write a feed payload and handler, or narrow with query().',
    replaces: [
        'a feed payload with one getter, setter and raw accessor per view parameter',
        'a feed handler with a field map, a query and a projection',
        'a JSON response class carrying the #[Collection*] attributes',
    ],
    seeAlso: 'ui.field-type',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCollectionFeed extends AbstractPayloadRoute implements CollectionFeedRouteInterface, DeclaresWatchScopesInterface
{
    /**
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string|null $source a CollectionSourceInterface service serving them instead (a repository, an API)
     * @param list<string>      $watch  the scopes a source-served feed refreshes on (a model-served one watches its model)
     * @param list<int>         $perPageOptions
     */
    public function __construct(
        string $path,
        string $name,
        public readonly ?string $model = null,
        public readonly string $paginationMode = CollectionPaginationPolicy::MODE_PAGE,
        public readonly int $defaultPerPage = 25,
        public readonly array $perPageOptions = [10, 25, 50, 100],
        public readonly int $maxPerPage = 100,
        public readonly int $countThreshold = 1000,
        ?string $doc = null,
        public readonly ?string $source = null,
        public readonly array $watch = [],
    ) {
        if (($model === null) === ($source === null)) {
            throw new \InvalidArgumentException(sprintf('#[AsCollectionFeed] "%s" names its rows by a model or by a source: give exactly one.', $name));
        }
        parent::__construct(
            doc: $doc,
            path: $path,
            methods: ['GET'],
            name: $name,
            responseWith: CollectionFeedJsonResponse::class,
            renderProfile: RenderProfile::Json,
            transport: TransportType::Sse,
            sseGateModel: SseGateModel::BearerSession,
        );
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Public;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function policy(): CollectionPaginationPolicy
    {
        return new CollectionPaginationPolicy(
            mode: $this->paginationMode,
            defaultPerPage: $this->defaultPerPage,
            perPageOptions: $this->perPageOptions,
            maxPerPage: $this->maxPerPage,
            countThreshold: $this->countThreshold,
        );
    }

    /**
     * The model's resource key (its #[ResourceKey], else its table): the
     * channel every ORM write to it publishes on, so the feed refreshes live
     * with no publish code.
     */
    public function watchScopes(string $payloadClass): array
    {
        return $this->model === null ? $this->watch : [ResourceMetadata::for($this->model)->getResourceKey()];
    }
}
