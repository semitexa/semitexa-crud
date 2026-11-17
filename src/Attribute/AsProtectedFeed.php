<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Crud\Domain\Model\FeedPaging;

/**
 * A collection feed for signed-in visitors, and its name says so: who may read
 * the rows is the first thing a reviewer reads, not an argument at the end of a
 * list. A feed over admin rows adds #[RequiresPermission]: signed in alone is
 * not enough for those.
 *
 *     #[AsProtectedFeed(path: '/admin/orders/feed', name: 'admin.orders.feed', model: OrderResource::class,
 *         paging: new FeedPaging(defaultPerPage: 50, perPageOptions: [50, 100]), defaultSort: '-total')]
 *     #[RequiresPermission('orders.read')]
 *     final class OrdersFeed extends CollectionFeed { … }
 *
 * The live stream is re-authorized against the visitor's session on every
 * refresh (SseGateModel::Subject): a visitor who signed out, or lost the
 * permission, stops receiving rows at the next one.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsProtectedFeed extends AbstractCollectionFeedRoute
{
    /**
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string<\Semitexa\Crud\Domain\Contract\CollectionSourceInterface>|null $source a service serving them instead (a repository, an API)
     * @param list<string>      $watch  the scopes a source-served feed refreshes on (a model-served one watches its model)
     * @param string|null       $defaultSort the order a request without a sort gets, as `?sort=` writes it; the field must be sortable
     */
    public function __construct(
        string $path,
        string $name,
        ?string $model = null,
        ?string $source = null,
        FeedPaging $paging = new FeedPaging(),
        array $watch = [],
        ?string $defaultSort = null,
        ?string $doc = null,
    ) {
        parent::__construct(
            path: $path,
            name: $name,
            model: $model,
            source: $source,
            paging: $paging,
            watch: $watch,
            defaultSort: $defaultSort,
            doc: $doc,
            sseGateModel: SseGateModel::Subject,
            reason: null,
        );
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Protected;
    }
}
