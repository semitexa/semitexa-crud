<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Api\Application\Service\Collection\CollectionFeedSupport;
use Semitexa\Core\Http\WatchScopesOf;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Application\Service\RouteContract\CollectionFeedContractContributor;
use Semitexa\Crud\Application\Service\Feed\FeedDeclarations;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\ResourceKey;
use Semitexa\Orm\Attribute\TenantExempt;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * tk-rs-feed: a feed is its fields. The criteria the request is checked
 * against, the OPTIONS contract (collection + ui) and each row all follow
 * from fields() — so they cannot disagree.
 */
final class CollectionFeedTest extends TestCase
{
    #[Test]
    public function the_fields_are_the_collection_declarations(): void
    {
        $feed = new ArticlesFeedFixture();
        $declarations = FeedDeclarations::of(new UiFieldSet($feed->fields()), $feed->declaration());

        self::assertSame(['title', 'updatedAt'], $declarations->sort);
        self::assertSame(['title'], $declarations->searchable?->fields);
        self::assertSame(['title' => ['contains', 'eq'], 'status' => ['eq']], $declarations->filter);
        self::assertSame(['status'], $declarations->filterOptions, 'a choice serves its options; free text does not');
        self::assertSame(10, $declarations->policy->defaultPerPage);

        $options = FeedDeclarations::filterOptions(new UiFieldSet($feed->fields()));
        self::assertSame([['value' => '', 'label' => '(any)'], ['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']], $options['status']);
    }

    #[Test]
    public function the_request_is_checked_against_what_the_fields_allow(): void
    {
        $feed = new ArticlesFeedFixture();
        $declarations = FeedDeclarations::of(new UiFieldSet($feed->fields()), $feed->declaration());
        $criteria = (new CollectionFeedSupport())->criteriaFrom($declarations, rawQ: 'php', rawSort: '-updatedAt', rawFilter: 'status:eq:draft');

        self::assertSame('php', $criteria->q);
        self::assertSame(['title'], $criteria->searchFields);

        $this->expectException(InvalidFilterException::class);
        (new CollectionFeedSupport())->criteriaFrom($declarations, rawFilter: 'body:contains:x'); // body is not filterable
    }

    #[Test]
    public function the_contract_carries_the_collection_and_the_ui_block(): void
    {
        $blocks = (new CollectionFeedContractContributor())->contributeBlocks(ArticlesFeedFixture::class, null);

        self::assertSame(['id', 'title', 'status', 'updatedAt'], array_column($blocks['ui']['columns'], 'field'));
        self::assertSame('id', $blocks['ui']['idField']);
        self::assertSame(['title', 'status'], array_keys($blocks['ui']['filters']));
        self::assertSame(['title', 'updatedAt'], $blocks['collection']['sort']['fields']);
        self::assertSame(['crud_fixture_articles'], $blocks['collection']['live']['scopes'], 'the model\'s resource key');
        self::assertSame([], (new CollectionFeedContractContributor())->contributeBlocks(\stdClass::class, null), 'not a feed: no blocks');
    }

    #[Test]
    public function the_watch_scope_is_the_models_resource_key(): void
    {
        self::assertSame(['crud_fixture_articles'], WatchScopesOf::payload(ArticlesFeedFixture::class));
    }

    #[Test]
    public function a_row_reads_the_property_or_its_snake_case_twin_and_is_json_plain(): void
    {
        $feed = new ArticlesFeedFixture();
        $model = new CrudFixtureArticleModel('a1', 'Hello', 'draft', 'Body', new \DateTimeImmutable('2026-10-06 09:30:00', new \DateTimeZone('Europe/Kyiv')));

        self::assertSame(
            ['id' => 'a1', 'title' => 'Hello', 'status' => 'draft', 'body' => 'Body', 'updatedAt' => '2026-10-06 06:30:00'],
            $feed->row($model, new UiFieldSet($feed->fields())),
            'updatedAt reads updated_at; the moment is written in UTC',
        );
    }

    #[Test]
    public function an_unmappable_field_says_which_and_how_to_fix_it(): void
    {
        $feed = new class () extends ArticlesFeedFixture {
            public function fields(): array { return [Field::text('nope')]; }
        };
        $this->expectExceptionMessage('has no property "nope"');
        $feed->row(new CrudFixtureArticleModel('a', 't', 's', 'b', new \DateTimeImmutable()), new UiFieldSet($feed->fields()));
    }

    #[Test]
    public function a_range_is_checked_by_its_field_s_type_and_a_day_on_a_moment_is_the_whole_day(): void
    {
        $fields = new UiFieldSet([Field::integer('stock')->filterable(), Field::datetime('updatedAt')->filterable(), Field::date('day')->filterable()]);
        $declarations = FeedDeclarations::of($fields, (new ArticlesFeedFixture())->declaration());
        $parse = static fn (string $filter): \Semitexa\Core\Resource\CollectionCriteria => FeedDeclarations::normalizeRanges(
            (new CollectionFeedSupport())->criteriaFrom($declarations, rawFilter: $filter),
            $fields,
        );

        self::assertSame('updatedAt:gte:2026-10-01 00:00:00;updatedAt:lte:2026-10-06 23:59:59', $parse('updatedAt:gte:2026-10-01;updatedAt:lte:2026-10-06')->filter->toQueryString());
        self::assertSame('stock:gte:5;day:lte:2026-10-06', $parse('stock:gte:5;day:lte:2026-10-06')->filter->toQueryString());

        foreach (['stock:gte:five', 'day:lte:2026-02-31', 'updatedAt:gte:yesterday'] as $bad) {
            try {
                $parse($bad);
                self::fail($bad . ' must be refused');
            } catch (\Semitexa\Core\Resource\Exception\InvalidFilterException) {
            }
        }
    }


    #[Test]
    public function a_counted_choice_says_how_many_records_it_holds(): void
    {
        $fields = new UiFieldSet([Field::belongsTo('categoryId')->label('Category')->options(['c1' => 'News', 'c2' => 'PHP'])->filterable()]);

        self::assertSame(
            [['value' => '', 'label' => '(any)'], ['value' => 'c1', 'label' => 'News (12)'], ['value' => 'c2', 'label' => 'PHP (0)']],
            FeedDeclarations::filterOptions($fields, ['categoryId' => ['c1' => 12]])['categoryId'],
            'a choice nothing points at still shows, as (0)',
        );
    }

}

#[AsCollectionFeed(path: '/crud-fixture/articles/feed', name: 'crud-fixture.articles.feed', model: CrudFixtureArticleModel::class, defaultPerPage: 10)]
class ArticlesFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [
            Field::id(),
            Field::text('title')->searchable()->sortable()->filterable(),
            Field::choice('status', ['draft', 'published'])->filterable(),
            Field::textarea('body'),
            Field::datetime('updatedAt')->sortable(),
        ];
    }
}

#[FromTable(name: 'crud_fixture_articles')]
#[ResourceKey('crud_fixture_articles')]
#[TenantExempt(reason: 'test fixture')]
final readonly class CrudFixtureArticleModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 160)]
        public string $title,
        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $status,
        #[Column(type: MySqlType::Text)]
        public string $body,
        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {
    }
}
