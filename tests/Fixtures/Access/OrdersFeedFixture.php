<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/** A feed that says nothing about access: signed-in visitors only. Read by CollectionFeedAccessTest. */
#[AsCollectionFeed(path: '/crud-fixture/orders/feed', name: 'crud-fixture.orders.feed', model: OrderRowFixture::class)]
final class OrdersFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id()];
    }
}
