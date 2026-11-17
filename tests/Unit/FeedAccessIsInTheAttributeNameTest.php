<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\RouteDeclarationGuard;
use Semitexa\Crud\Attribute\AbstractCollectionFeedRoute;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\Crud\Attribute\AsProtectedFeed;
use Semitexa\Crud\Attribute\AsPublicFeed;
use Semitexa\Crud\Domain\Model\FeedPaging;
use Semitexa\Crud\Tests\Fixtures\Access\OrderRowFixture;
use Semitexa\Crud\Tests\Fixtures\Access\OrderRowsSourceFixture;
use Semitexa\Crud\Tests\Fixtures\Access\ProtectedOrdersFeedFixture;
use Semitexa\Crud\Tests\Fixtures\Access\PublicPricesFeedFixture;

/**
 * ep-feeds-declare-their-access: who may read a feed's rows is the attribute's
 * name, the first thing a reviewer reads, not an argument at the end of a list.
 * #[AsProtectedFeed] is for signed-in visitors and re-authorized on every live
 * refresh; #[AsPublicFeed] is for anyone and says why. Paging is one value,
 * given whole.
 */
final class FeedAccessIsInTheAttributeNameTest extends TestCase
{
    #[Test]
    public function a_protected_feed_is_for_signed_in_visitors_and_re_authorized_on_every_refresh(): void
    {
        $feed = new ProtectedOrdersFeedFixture();
        $route = $feed->declaration();

        self::assertInstanceOf(AsProtectedFeed::class, $route);
        self::assertSame(PayloadAccessType::Protected, (new PayloadAccessPolicyResolver())->accessType($feed));
        self::assertSame(['orders.read'], (new PayloadAccessPolicyResolver())->requiredPermissions($feed));
        self::assertSame(SseGateModel::Subject, $route->sseGateModel);
        self::assertSame(TransportType::Sse, $route->transport);
        self::assertSame(['/crud-fixture/protected/orders/feed', ['GET'], 'crud-fixture.protected.orders.feed'], [$route->path, $route->methods, $route->name]);
        self::assertSame([OrderRowFixture::class, null, '-id'], [$route->model(), $route->source(), $route->defaultSort()]);
        $policy = $route->policy();
        self::assertSame(['page', 50, [50, 100], 100, 1000], [$policy->mode, $policy->defaultPerPage, $policy->perPageOptions, $policy->maxPerPage, $policy->countThreshold]);
    }

    #[Test]
    public function a_public_feed_says_why_and_its_stream_admits_guests(): void
    {
        $feed = new PublicPricesFeedFixture();
        $route = $feed->declaration();

        self::assertInstanceOf(AsPublicFeed::class, $route);
        self::assertSame(PayloadAccessType::Public, (new PayloadAccessPolicyResolver())->accessType($feed));
        self::assertSame('the public price list', $route->reason);
        self::assertSame(SseGateModel::BearerSession, $route->sseGateModel);
        $policy = $route->policy();
        self::assertSame(['page', 25, [10, 25, 50, 100], 100, 1000], [$policy->mode, $policy->defaultPerPage, $policy->perPageOptions, $policy->maxPerPage, $policy->countThreshold]);
    }

    #[Test]
    public function the_boot_guard_accepts_both_gates(): void
    {
        $guard = new RouteDeclarationGuard();
        foreach ([ProtectedOrdersFeedFixture::class, PublicPricesFeedFixture::class] as $class) {
            $route = (new \ReflectionClass($class))->getAttributes(AbstractCollectionFeedRoute::class, \ReflectionAttribute::IS_INSTANCEOF)[0]->newInstance();
            // Throws a ConfigurationException on a Subject gate over a public route, or an SSE route with no gate.
            $guard->assertSseGateCoherence(TransportType::Sse->value, $route->sseGateModel, $route->getAccessType(), $class);
        }
        $this->addToAssertionCount(2);
    }

    #[Test]
    public function a_public_feed_without_a_reason_is_refused(): void
    {
        foreach (['', '  '] as $reason) {
            try {
                new AsPublicFeed(path: '/x/feed', name: 'x.feed', reason: $reason, model: OrderRowFixture::class);
                self::fail('a public feed with reason ' . var_export($reason, true) . ' was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertSame("#[AsPublicFeed] \"x.feed\": say why anyone may read its rows, reason: 'the public price list'.", $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_feed_names_its_rows_by_a_model_or_a_source_once(): void
    {
        $source = OrderRowsSourceFixture::class;
        $attempts = [
            'AsProtectedFeed: neither' => static fn (): object => new AsProtectedFeed(path: '/x/feed', name: 'x.feed'),
            'AsProtectedFeed: both' => static fn (): object => new AsProtectedFeed(path: '/x/feed', name: 'x.feed', model: OrderRowFixture::class, source: $source),
            'AsPublicFeed: neither' => static fn (): object => new AsPublicFeed(path: '/x/feed', name: 'x.feed', reason: 'open data'),
            'AsPublicFeed: both' => static fn (): object => new AsPublicFeed(path: '/x/feed', name: 'x.feed', reason: 'open data', model: OrderRowFixture::class, source: $source),
        ];
        foreach ($attempts as $case => $make) {
            try {
                $make();
                self::fail($case . ' was accepted');
            } catch (\InvalidArgumentException $e) {
                $attribute = strstr($case, ':', true);
                self::assertSame('#[' . $attribute . '] "x.feed" names its rows by a model or by a source: give exactly one.', $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function the_old_attribute_is_the_same_feed_on_the_shared_base(): void
    {
        $route = new AsCollectionFeed(
            path: '/x/feed',
            name: 'x.feed',
            model: OrderRowFixture::class,
            paginationMode: 'auto',
            defaultPerPage: 10,
            perPageOptions: [10, 20],
            maxPerPage: 20,
            countThreshold: 500,
        );

        self::assertInstanceOf(AbstractCollectionFeedRoute::class, $route);
        self::assertEquals(new FeedPaging(mode: 'auto', defaultPerPage: 10, perPageOptions: [10, 20], maxPerPage: 20, countThreshold: 500), $route->paging);
        self::assertSame(['auto', 10, [10, 20], 20, 500], [$route->paginationMode, $route->defaultPerPage, $route->perPageOptions, $route->maxPerPage, $route->countThreshold]);
        self::assertSame([PayloadAccessType::Protected, SseGateModel::BearerSession], [$route->getAccessType(), $route->sseGateModel]);
    }
}
