<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/** A feed anyone may read, saying why. Read by CollectionFeedAccessTest. */
#[AsCollectionFeed(path: '/crud-fixture/prices/feed', name: 'crud-fixture.prices.feed', model: OrderRowFixture::class,
    access: PayloadAccessType::Public, reason: 'the public price list')]
final class PriceListFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id()];
    }
}
