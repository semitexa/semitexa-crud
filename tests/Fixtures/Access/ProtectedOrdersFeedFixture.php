<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsProtectedFeed;
use Semitexa\Crud\Domain\Model\FeedPaging;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/** A protected feed over admin rows, its paging given whole. Read by FeedAccessIsInTheAttributeNameTest. */
#[AsProtectedFeed(
    path: '/crud-fixture/protected/orders/feed',
    name: 'crud-fixture.protected.orders.feed',
    model: OrderRowFixture::class,
    paging: new FeedPaging(defaultPerPage: 50, perPageOptions: [50, 100]),
    defaultSort: '-id',
)]
#[RequiresPermission('orders.read')]
final class ProtectedOrdersFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id()->sortable()];
    }
}
