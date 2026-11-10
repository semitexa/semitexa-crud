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
    usage: <<<'USAGE'
        // Signed-in visitors only by default; a grid anyone may see says so:
        //   access: PayloadAccessType::Public, reason: 'the public price list'
        // and one over admin rows names who may read them: #[RequiresPermission('orders.read')].
        #[AsCollectionFeed(path: '/orders/feed', name: 'orders.feed', model: OrderResource::class,
            defaultPerPage: 50, perPageOptions: [50, 100], defaultSort: '-total')]
        final class OrdersFeed extends CollectionFeed
        {
            public function fields(): array
            {
                return [
                    Field::id()->sortable(),
                    Field::text('number')->searchable()->sortable(),
                    Field::choice('status', ['new' => 'New', 'paid' => 'Paid'])->sortable()->filterable(),
                    Field::integer('total')->sortable(),
                ];
            }
        }

        // In the page, with paging, sorting, search and live updates on the server (100,000 rows
        // measured: first rows 206 ms, next page 313 ms):
        {{ component('platform.grid', {endpoint: '/orders/feed', urlState: 'o'}) }}

        // Order goes in defaultSort, not in query(): an orderBy there outranks the visitor's own sort.
        // A primary key with another name is listed as Field::id('code'). Search and filters apply
        // on Enter or Apply, and the row markup is the grid's. For other markup or search-as-you-type, write one
        // #[AsComponent] with #[UiUrl] props and #[UiOn] handlers: ai:ask mechanisms --id=ui.url-state.
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCollectionFeed extends AbstractPayloadRoute implements CollectionFeedRouteInterface, DeclaresWatchScopesInterface
{
    /**
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string|null $source a CollectionSourceInterface service serving them instead (a repository, an API)
     * @param list<string>      $watch  the scopes a source-served feed refreshes on (a model-served one watches its model)
     * @param list<int>         $perPageOptions
     * @param string|null       $defaultSort the order a request without a sort gets, as `?sort=` writes it; the field must be sortable
     * @param PayloadAccessType $access who may read the rows: Protected (the default) is signed-in
     *        visitors only, as a page from make:page is; Public is anyone, and says why in $reason
     * @param string|null       $reason why a public feed is public, in a sentence
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
        public readonly ?string $defaultSort = null,
        public readonly PayloadAccessType $access = PayloadAccessType::Protected,
        ?string $reason = null,
    ) {
        if (($model === null) === ($source === null)) {
            throw new \InvalidArgumentException(sprintf('#[AsCollectionFeed] "%s" names its rows by a model or by a source: give exactly one.', $name));
        }
        // A feed is rows for a grid a person looks at: a machine endpoint is its own payload.
        if ($access === PayloadAccessType::Service) {
            throw new \InvalidArgumentException(sprintf('#[AsCollectionFeed] "%s" is read by a grid, so it is Protected or Public; a machine endpoint is an #[AsServicePayload] of its own.', $name));
        }
        // Rows anyone may read are a decision, so the attribute says whose (review, crud#11).
        if ($access === PayloadAccessType::Public && trim((string) $reason) === '') {
            throw new \InvalidArgumentException(sprintf("#[AsCollectionFeed] \"%s\" is Public: say why anyone may read its rows, reason: 'the public price list'.", $name));
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
            reason: $reason,
        );
    }

    /**
     * Every feed was Public whatever its rows (tk-collection-feed-always-public):
     * a feed over an admin model handed its rows to anyone who knew the path.
     */
    public function getAccessType(): PayloadAccessType
    {
        return $this->access;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function defaultSort(): ?string
    {
        return $this->defaultSort;
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
