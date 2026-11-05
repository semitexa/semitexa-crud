<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Api\Application\Service\Collection\CollectionFeedSupport;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Crud\Application\Handler\PayloadHandler\CollectionFeedHandler;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Crud\Application\Service\RouteContract\CollectionFeedContractContributor;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\ResourceKey;
use Semitexa\Orm\Attribute\TenantExempt;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\CollectionQueryCompiler;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * A feed served for real, over SQLite. The compiler breaks every sort tie by "id":
 * a model whose primary key has another name has no such column, so its feed
 * answered 500 on any request. Rows also start in the order the route declares.
 */
final class CollectionFeedServedTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE crud_fixture_skus (code VARCHAR(32) PRIMARY KEY, name VARCHAR(160) NOT NULL)');
        foreach (['c' => 'same', 'a' => 'same', 'b' => 'same', 'd' => 'other'] as $code => $name) {
            $this->orm->getAdapter()->execute('INSERT INTO crud_fixture_skus (code, name) VALUES (:code, :name)', ['code' => $code, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function rows_come_in_primary_key_order_when_nothing_else_decides(): void
    {
        self::assertSame(['a', 'b'], self::codes($this->serve(new SkusFeed())));
    }

    #[Test]
    public function equal_sort_values_fall_back_to_the_primary_key(): void
    {
        $feed = new SkusFeed();
        $feed->setSort('-name');
        $feed->setPage('2');

        self::assertSame(['c', 'd'], self::codes($this->serve($feed)));
    }

    #[Test]
    public function a_cursor_walks_every_row_once(): void
    {
        $seen = [];
        $cursor = null;
        do {
            $feed = new SkusCursorFeed();
            $feed->setSort('name');
            if ($cursor !== null) {
                $feed->setCursor($cursor);
            }
            $page = $this->serve($feed);
            array_push($seen, ...self::codes($page));
            $cursor = $page['meta']['pagination']['nextCursor'] ?? null;
        } while ($cursor !== null);

        self::assertSame(['d', 'a', 'b', 'c'], $seen);
    }

    #[Test]
    public function a_feed_starts_in_its_default_order_and_the_visitor_can_leave_it(): void
    {
        self::assertSame(['d', 'a'], self::codes($this->serve(new SkusByNameFeed())), 'without the default, a and b come first');

        $visitor = new SkusByNameFeed();
        $visitor->setSort('-name');
        self::assertSame(['a', 'b'], self::codes($this->serve($visitor)));
    }

    #[Test]
    public function the_contract_carries_the_default_to_the_grid(): void
    {
        $blocks = (new CollectionFeedContractContributor())->contributeBlocks(SkusByNameFeed::class, null);

        self::assertSame('name', $blocks['collection']['sort']['default'] ?? null);
    }

    #[Test]
    public function a_field_named_id_on_another_column_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Feed field "id" reads "name"');

        $this->serve(new SkusIdOnNameFeed());
    }

    /** @return array<string, mixed> */
    private function serve(CollectionFeed $feed): array
    {
        $handler = new CollectionFeedHandler();
        foreach ([
            'feedSupport' => new CollectionFeedSupport(),
            'compiler' => new CollectionQueryCompiler(),
            'orm' => $this->orm,
            'tenantContextStore' => $this->createMock(TenantContextStoreInterface::class),
            'relations' => new RelationOptions(),
            'container' => $this->createMock(ContainerInterface::class),
        ] as $property => $value) {
            (new \ReflectionProperty(CollectionFeedHandler::class, $property))->setValue($handler, $value);
        }

        $response = (new \ReflectionMethod(CollectionFeedHandler::class, 'buildCollectionResponse'))->invoke($handler, $feed, new CollectionFeedJsonResponse());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private static function codes(array $page): array
    {
        return array_column($page['data'], 'code');
    }
}

/** A model whose primary key is not called id. */
#[FromTable(name: 'crud_fixture_skus')]
#[ResourceKey('crud_fixture_skus')]
#[TenantExempt(reason: 'test fixture')]
final readonly class SkuResource
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $code,
        #[Column(type: MySqlType::Varchar, length: 160)]
        public string $name,
    ) {
    }
}

#[AsCollectionFeed(path: '/crud-fixture/skus/feed', name: 'crud-fixture.skus.feed', model: SkuResource::class, defaultPerPage: 2, perPageOptions: [2])]
class SkusFeed extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::id('code'), Field::text('name')->sortable()];
    }
}

#[AsCollectionFeed(path: '/crud-fixture/skus/by-name', name: 'crud-fixture.skus.by-name', model: SkuResource::class, defaultPerPage: 2, perPageOptions: [2], defaultSort: 'name')]
final class SkusByNameFeed extends SkusFeed
{
}

#[AsCollectionFeed(path: '/crud-fixture/skus/id-on-name', name: 'crud-fixture.skus.id-on-name', model: SkuResource::class, defaultPerPage: 2, perPageOptions: [2])]
final class SkusIdOnNameFeed extends SkusFeed
{
    public function fields(): array
    {
        return [Field::text('id')->set('property', 'name')->sortable()];
    }
}

#[AsCollectionFeed(path: '/crud-fixture/skus/cursor', name: 'crud-fixture.skus.cursor', model: SkuResource::class, paginationMode: CollectionPaginationPolicy::MODE_CURSOR, defaultPerPage: 2, perPageOptions: [2])]
final class SkusCursorFeed extends SkusFeed
{
}
