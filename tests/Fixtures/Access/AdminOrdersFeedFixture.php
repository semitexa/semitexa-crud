<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/** A feed for those who hold a permission, not for every signed-in visitor. Read by CollectionFeedAccessTest. */
#[AsCollectionFeed(path: '/crud-fixture/admin/orders/feed', name: 'crud-fixture.admin.orders.feed', model: OrderRowFixture::class)]
#[RequiresPermission('orders.read')]
final class AdminOrdersFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id()];
    }
}
