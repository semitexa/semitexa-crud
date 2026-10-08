<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * tk-rs-acceptance: what Articles and Leads needed — relation fields shown by
 * their labels and checked against their current choices, a feed served by a
 * source instead of the ORM, and an application's own permission words.
 */
final class RelationsAndSourcesTest extends TestCase
{
    #[Test]
    public function a_relation_reads_as_its_choices_labels(): void
    {
        $fields = new UiFieldSet([
            Field::belongsTo('categoryId')->options(['c1' => 'News', '7' => 'PHP']),
            Field::belongsToMany('tags')->options(['t1' => 'Swoole', 't2' => 'ORM']),
        ]);
        $feed = new SourceFeedFixture();
        $model = new class {
            public string $category_id = '7';
            /** @var list<object> */
            public array $tags = [];
        };
        $model->tags = [(object) ['id' => 't2'], (object) ['id' => 't1']];

        self::assertSame(['categoryId' => 'PHP', 'tags' => 'ORM, Swoole'], $feed->row($model, $fields), 'a numeric id stays a key, not a position');
    }

    #[Test]
    public function a_relation_value_must_still_be_a_choice(): void
    {
        $fields = [
            Field::belongsTo('categoryId')->options(['c1' => 'News']),
            Field::belongsToMany('tags')->options(['t1' => 'Swoole']),
            Field::text('title'),
        ];

        self::assertSame([], RelationOptions::unknownChoices($fields, ['categoryId' => 'c1', 'tags' => ['t1'], 'title' => 'x']));
        self::assertSame(['categoryId', 'tags'], array_keys(RelationOptions::unknownChoices($fields, ['categoryId' => 'gone', 'tags' => ['t1', 'gone'], 'title' => 'x'])));
        self::assertSame([], RelationOptions::unknownChoices($fields, ['categoryId' => null, 'tags' => []]), 'none is a choice');
    }

    #[Test]
    public function a_feed_names_a_model_or_a_source_and_a_source_names_its_watch(): void
    {
        $route = (new SourceFeedFixture())->declaration();
        self::assertNull($route->model());
        self::assertSame(\stdClass::class, $route->source());
        self::assertSame(['inbox'], $route->watchScopes(SourceFeedFixture::class));

        $this->expectException(\InvalidArgumentException::class);
        new AsCollectionFeed(path: '/x', name: 'x.feed');
    }

    #[Test]
    public function an_operation_can_need_the_application_s_own_permission(): void
    {
        $crud = new AsCrud(id: 'lab.articles', path: '/a', model: \stdClass::class, label: 'Article', permission: 'content', permissions: ['create' => 'content.publish', 'delete' => 'content.publish']);

        self::assertSame(['content.read', 'content.publish', 'content.edit', 'content.publish'], array_map($crud->permissionFor(...), AsCrud::OPERATIONS));

        $this->expectException(\InvalidArgumentException::class);
        new AsCrud(id: 'lab.articles', path: '/a', model: \stdClass::class, label: 'Article', permissions: ['publish' => 'x']);
    }
}

#[AsCollectionFeed(path: '/inbox/feed', name: 'inbox.feed', source: \stdClass::class, watch: ['inbox'])]
final class SourceFeedFixture extends CollectionFeed
{
    public function fields(): array
    {
        return [Field::text('title')];
    }
}
