<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Crud\Domain\Model\FeedPaging;

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
final class AsCollectionFeed extends AbstractCollectionFeedRoute
{
    /**
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string<\Semitexa\Crud\Domain\Contract\CollectionSourceInterface>|null $source a service serving them instead (a repository, an API)
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
        ?string $model = null,
        public readonly string $paginationMode = CollectionPaginationPolicy::MODE_PAGE,
        public readonly int $defaultPerPage = 25,
        public readonly array $perPageOptions = [10, 25, 50, 100],
        public readonly int $maxPerPage = 100,
        public readonly int $countThreshold = 1000,
        ?string $doc = null,
        ?string $source = null,
        array $watch = [],
        ?string $defaultSort = null,
        public readonly PayloadAccessType $access = PayloadAccessType::Protected,
        ?string $reason = null,
    ) {
        // A feed is rows for a grid a person looks at: a machine endpoint is its own payload.
        if ($access === PayloadAccessType::Service) {
            throw new \InvalidArgumentException(sprintf('#[AsCollectionFeed] "%s" is read by a grid, so it is Protected or Public; a machine endpoint is an #[AsServicePayload] of its own.', $name));
        }
        // Rows anyone may read are a decision, so the attribute says whose (review, crud#11).
        if ($access === PayloadAccessType::Public && trim((string) $reason) === '') {
            throw new \InvalidArgumentException(sprintf("#[AsCollectionFeed] \"%s\" is Public: say why anyone may read its rows, reason: 'the public price list'.", $name));
        }
        parent::__construct(
            path: $path,
            name: $name,
            model: $model,
            source: $source,
            paging: new FeedPaging(
                mode: $paginationMode,
                defaultPerPage: $defaultPerPage,
                perPageOptions: $perPageOptions,
                maxPerPage: $maxPerPage,
                countThreshold: $countThreshold,
            ),
            watch: $watch,
            defaultSort: $defaultSort,
            doc: $doc,
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
}
