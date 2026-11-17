<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsPublicFeed;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/** A feed anyone may read, and why. Read by FeedAccessIsInTheAttributeNameTest. */
#[AsPublicFeed(
    path: '/crud-fixture/public/prices/feed',
    name: 'crud-fixture.public.prices.feed',
    reason: 'the public price list',
    model: OrderRowFixture::class,
)]
final class PublicPricesFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id()];
    }
}
