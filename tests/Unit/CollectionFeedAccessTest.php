<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\Crud\Tests\Fixtures\Access\OrderRowFixture;
use Semitexa\Crud\Tests\Fixtures\Access\AdminOrdersFeedFixture;
use Semitexa\Crud\Tests\Fixtures\Access\OrdersFeedFixture;
use Semitexa\Crud\Tests\Fixtures\Access\PriceListFeedFixture;

/**
 * Who may read a collection feed (tk-collection-feed-always-public).
 *
 * Every #[AsCollectionFeed] was Public, whatever its rows: a feed over an
 * admin model handed them to anyone who knew its path. A feed is now
 * Protected unless it says otherwise, as a page from make:page is, and a
 * public one says why.
 */
final class CollectionFeedAccessTest extends TestCase
{
    #[Test]
    public function a_feed_that_says_nothing_is_for_signed_in_visitors(): void
    {
        self::assertSame(PayloadAccessType::Protected, (new PayloadAccessPolicyResolver())->accessType(new OrdersFeedFixture()));
    }

    #[Test]
    public function a_feed_over_admin_rows_asks_for_a_permission(): void
    {
        // Signed in is not enough for an admin model: the feed names the permission (review, Fable).
        $resolver = new PayloadAccessPolicyResolver();
        self::assertSame(PayloadAccessType::Protected, $resolver->accessType(new AdminOrdersFeedFixture()));
        self::assertSame(['orders.read'], $resolver->requiredPermissions(new AdminOrdersFeedFixture()));
    }

    #[Test]
    public function a_public_feed_says_so_and_why(): void
    {
        self::assertSame(PayloadAccessType::Public, (new PayloadAccessPolicyResolver())->accessType(new PriceListFeedFixture()));
        $route = (new \ReflectionClass(PriceListFeedFixture::class))->getAttributes(AsCollectionFeed::class)[0]->newInstance();
        self::assertSame('the public price list', $route->reason);
    }

    #[Test]
    public function a_feed_is_not_a_machine_endpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is read by a grid, so it is Protected or Public');
        new AsCollectionFeed(path: '/x/feed', name: 'x.feed', model: OrderRowFixture::class, access: PayloadAccessType::Service);
    }
}
