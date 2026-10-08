<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Application\Resource\Response\CrudPageResponse;
use Semitexa\Crud\Application\Service\Palette\CrudScreenCommands;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Crud\Application\Service\Submit\CrudDeleteAction;
use Semitexa\Crud\Application\Service\Submit\CrudSaveAction;
use Semitexa\Crud\Application\Service\Twig\CrudTwigExtension;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlerInterface;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlers;
use Semitexa\Crud\Application\Service\Grid\CrudGridAction;
use Semitexa\Crud\Attribute\AsCrudAction;
use Semitexa\Crud\Domain\Model\CrudAction;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridAction;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionContext;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantExempt;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * tk-rs-crud-runtime: one #[AsCrud] class is a protected route with two
 * profiles, a set of permissions, a form target the browser cannot redirect,
 * a writer that keeps what the form does not show, navigation and commands.
 */
final class CrudScreenTest extends TestCase
{
    private FakeCrudAdapter $db;

    protected function setUp(): void
    {
        CrudActionHandlers::add(new MarkGadgetsFixture());
        CrudScreens::discover([GadgetCrudFixture::class, PartCrudFixture::class]);
        $this->db = new FakeCrudAdapter();
        $this->grant(['gadgets.read', 'gadgets.create', 'gadgets.edit']);
    }

    protected function tearDown(): void
    {
        CrudScreens::reset();
        CrudActionHandlers::reset();
        UiPermissions::reset();
    }

    #[Test]
    public function the_attribute_is_one_protected_route_with_a_page_and_a_collection(): void
    {
        $crud = (new GadgetCrudFixture())->crud();

        self::assertSame(PayloadAccessType::Protected, $crud->getAccessType());
        self::assertSame('test.gadgets', $crud->name);
        self::assertSame([RenderProfile::Html, RenderProfile::Json], $crud->renderProfile, 'HTML first: */* gets the page');
        self::assertSame(['html' => CrudPageResponse::class, 'json' => CollectionFeedJsonResponse::class], $crud->responsesByProfile);
        self::assertSame(['gadgets.read'], $crud->requiredPermissions(GadgetCrudFixture::class));
        self::assertSame('gadgets.delete', $crud->permissionFor('delete'));
        self::assertSame(['crud_gadgets'], $crud->watchScopes(GadgetCrudFixture::class));
        self::assertSame('Gadgets', $crud->plural());
        self::assertSame(['Categories', 'Boxes', 'Days', 'Products'], array_map(AsCrud::pluralOf(...), ['Category', 'Box', 'Day', 'Product']));
        self::assertNull((new PartCrudFixture())->crud()->permissionFor('edit'), 'no permission: signed in is enough');

        $this->expectException(\InvalidArgumentException::class);
        new AsCrud(id: 'Gadgets', path: '/x', model: CrudGadgetModel::class, label: 'Gadget');
    }

    #[Test]
    public function two_screens_with_one_id_fail_boot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declared twice');
        CrudScreens::discover([GadgetCrudFixture::class, GadgetTwinCrudFixture::class]);
    }

    #[Test]
    public function a_create_casts_the_form_and_stamps_what_the_form_does_not_show(): void
    {
        $result = $this->save(['name' => '  Widget ', 'sku' => 'W-1', 'price' => '19.90', 'launchDate' => '2026-10-06']);

        self::assertTrue($result->accepted, $result->message);
        self::assertSame('Gadget created.', $result->message);
        self::assertTrue($result->closeModal);
        self::assertTrue($result->reset, 'the create form is ready for the next one');
        $insert = $this->db->columns('INSERT');
        self::assertSame('Widget', $insert['name']);
        self::assertSame('w-1', $insert['sku'], 'a slug is stored lowercase');
        self::assertSame('19.90', $insert['price']);
        self::assertSame('2026-10-06', $insert['launch_date']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $insert['created_at']);
        self::assertNotSame('', $insert['id']);
    }

    #[Test]
    public function an_edit_keeps_the_record_s_other_values_and_carries_the_signed_version(): void
    {
        $this->db->rows = [self::storedGadget()];

        $result = $this->save(['name' => 'Renamed', 'sku' => 'g-1', 'price' => '5.00'], record: 'gad-1', version: 3);

        self::assertTrue($result->accepted, $result->message);
        self::assertFalse($result->reset);
        $update = $this->db->last('UPDATE');
        $set = $this->db->columns('UPDATE');
        self::assertSame('Renamed', $set['name']);
        self::assertSame('2026-01-01 00:00:00', $set['created_at'], 'not on the form: kept');
        self::assertMatchesRegularExpression('/`version` = :\w+/', $update['sql']);
        self::assertContains(3, $update['params'], 'the version the form was rendered with guards the write');
    }

    #[Test]
    public function the_unique_index_answers_as_a_field_error(): void
    {
        $this->db->taken = true;

        $result = $this->save(['name' => 'Twin', 'sku' => 'g-1', 'price' => '1']);

        self::assertFalse($result->accepted);
        self::assertSame(['sku' => 'Another gadget already has this sku.'], $result->fieldErrors);
        self::assertNull($this->db->last('INSERT'));
    }

    #[Test]
    public function the_permission_is_checked_for_the_operation_and_the_screen(): void
    {
        $this->grant(['gadgets.read']);
        self::assertSame('You may not create gadgets.', $this->save(['name' => 'x', 'sku' => 'x', 'price' => '1'])->message);

        $this->db->rows = [self::storedGadget()];
        self::assertSame('You may not delete gadgets.', $this->delete('gad-1')->message);

        $context = new UiFormSubmitActionContext('uci_crud_form_0001', CrudSaveAction::NAME, 'ui_evt_x', [], [], UiFormSubmitResult::fromFieldResults([]), null, ['crud' => 'test.nothing']);
        self::assertSame('This form does not name a screen it may write.', $this->action(CrudSaveAction::class)->handle($context)->message);
    }

    #[Test]
    public function a_record_outside_the_screen_s_query_cannot_be_edited(): void
    {
        $this->db->rows = []; // the narrowed query finds nothing

        $result = $this->save(['name' => 'x', 'sku' => 'x', 'price' => '1'], record: 'gad-9');

        self::assertSame('This gadget no longer exists.', $result->message);
        self::assertNull($this->db->last('UPDATE'));
        self::assertStringContainsString('`name` LIKE', (string) $this->db->first('SELECT')['sql'], 'found through query(), not around it');
    }

    #[Test]
    public function an_owned_relation_that_reads_back_empty_is_never_written_over(): void
    {
        $this->grant(['parts.read', 'parts.edit']);
        $this->db->rows = [['id' => 'p-1', 'name' => 'Part']];

        // The ORM refuses such a model outright (it could not say "not loaded"),
        // so no screen ever gets to write it.
        $this->expectException(\Semitexa\Orm\Exception\InvalidRelationDeclarationException::class);
        $this->expectExceptionMessage('must be typed to hold');
        $this->action(CrudSaveAction::class)->handle($this->context(['name' => 'x'], ['crud' => 'test.parts', 'record' => 'p-1']));
    }

    #[Test]
    public function navigation_and_commands_carry_the_permission_to_read(): void
    {
        $nav = CrudTwigExtension::nav([['label' => 'Shop', 'items' => [['label' => 'Home', 'href' => '/']]]]);
        self::assertSame(['Home', 'Gadgets'], array_column($nav[0]['items'], 'label'));
        self::assertSame('gadgets.read', $nav[0]['items'][1]['permission'], 'the app shell lists it only for who may read');
        self::assertSame('Workshop', $nav[1]['label']);
        self::assertNull($nav[1]['items'][0]['permission'], 'parts need only a sign-in');

        $this->grant(['gadgets.read', 'gadgets.create']);
        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('gadg', 10), false);
        self::assertSame(['Gadgets', 'New gadget'], array_map(static fn ($c) => $c->title, $commands));
        self::assertSame(['gadgets.read', 'gadgets.create'], array_map(static fn ($c) => $c->permission, $commands));
        self::assertSame('/crud/gadgets?create', $commands[1]->href);
    }

    #[Test]
    public function a_guest_is_not_offered_screens_that_need_a_sign_in(): void
    {
        // The palette shows an item without a permission to everybody; a
        // screen without one is for signed-in visitors, so the source decides.
        UiPermissions::reset();
        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('part', 10), false);
        self::assertSame([], $commands, 'a guest sees no screen names or paths');

        $this->grant([]);
        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('part', 10), false);
        self::assertContains('Parts', array_map(static fn ($c) => $c->title, $commands), 'signed in is enough for parts');

        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('gadg', 10), false);
        self::assertSame([], $commands, 'gadgets need gadgets.read');
    }

    #[Test]
    public function ctrl_k_finds_records_by_the_screen_s_searchable_fields_through_its_query(): void
    {
        $this->db->rows = [self::storedGadget()];

        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('Gad_', 10), false);

        self::assertSame(['Gadget'], array_map(static fn ($c) => $c->title, $commands), 'no screen is named "gad_"; a record is');
        self::assertSame('/crud/gadgets?edit=gad-1', $commands[0]->href, 'an editor goes to the edit dialog');
        self::assertSame(['Gadgets', 'Gadget'], [$commands[0]->group, $commands[0]->subtitle]);
        self::assertSame('gadgets.read', $commands[0]->permission);
        $select = (string) $this->db->first('SELECT')['sql'];
        self::assertStringContainsString('`name` LIKE', $select, 'by the searchable field, inside query()');
        self::assertStringNotContainsString('`sku` LIKE', $select, 'not by a field the grid does not search');
        self::assertContains('%Gad\\_%', $this->db->first('SELECT')['params'], 'the visitor\'s wildcards match literally');

        $this->grant(['gadgets.read']);
        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('Gad', 10), false);
        self::assertSame(['Gadgets', 'Gadget'], array_map(static fn ($c) => $c->title, $commands), 'screens first, then records; no "New gadget" without gadgets.create');
        self::assertSame('/crud/gadgets?view=gad-1', $commands[1]->href, 'a reader goes to the view dialog');

        $this->grant([]);
        $this->db->executed = [];
        $commands = iterator_to_array($this->action(CrudScreenCommands::class)->search('Gad', 10), false);
        self::assertSame([], $commands, 'no screen and no record the visitor may not read');
        self::assertNull($this->db->first('SELECT'), 'a screen the visitor may not read is not even queried');
    }

    #[Test]
    public function a_tree_may_open_a_screen_s_dialog_only_for_who_may_run_it(): void
    {
        $create = new \Semitexa\Crud\Application\Service\Tree\CrudCreateTreeAction();
        $edit = new \Semitexa\Crud\Application\Service\Tree\CrudEditTreeAction();

        self::assertSame([], $create->check(['kind' => 'create', 'screen' => 'test.gadgets'], '/actions/new'));
        self::assertSame('/crud/gadgets?create', $create->propValue(['kind' => 'create', 'screen' => 'test.gadgets']));
        self::assertSame('/crud/gadgets?edit=gad%201', $edit->propValue(['kind' => 'edit', 'screen' => 'test.gadgets', 'record' => 'gad 1']));
        self::assertSame(['tree.action_record'], array_map(static fn ($e) => $e->code, $edit->check(['kind' => 'edit', 'screen' => 'test.gadgets'], '/actions/e')));
        self::assertSame(['tree.action_screen'], array_map(static fn ($e) => $e->code, $create->check(['kind' => 'create', 'screen' => 'test.nothing'], '/actions/new')));

        $this->grant(['gadgets.read']);
        $refused = $create->check(['kind' => 'create', 'screen' => 'test.gadgets'], '/actions/new');
        self::assertSame(['tree.action_forbidden'], array_map(static fn ($e) => $e->code, $refused));
        self::assertSame('This visitor may not create gadgets.', $refused[0]->message);
    }

    #[Test]
    public function a_bulk_delete_finds_the_records_through_the_screen_and_deletes_each(): void
    {
        $this->grant(['gadgets.read', 'gadgets.delete']);
        $this->db->rows = [self::storedGadget(), ['id' => 'gad-2'] + self::storedGadget()];

        $result = $this->gridAction(CrudAction::delete(), 'bulk', ['gad-1', 'gad-2']);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('Deleted 2 gadgets.', $result->message);
        self::assertSame(2, $result->affected);
        self::assertCount(2, array_filter($this->db->executed, static fn (array $s): bool => str_starts_with($s['sql'], 'DELETE')));
        self::assertStringContainsString('`name` LIKE', (string) $this->db->first('SELECT')['sql'], 'through query(), not around it');
    }

    #[Test]
    public function a_grid_action_is_refused_without_its_permission_or_off_the_screen(): void
    {
        $this->db->rows = [self::storedGadget()];
        self::assertSame('You may not delete gadgets.', $this->gridAction(CrudAction::delete(), 'row', ['gad-1'])->message);
        self::assertNull($this->db->last('DELETE'));

        // the page offered "mark" but the screen (not the page) decides what exists
        $this->grant(['gadgets.read', 'gadgets.edit', 'gadgets.delete']);
        self::assertSame('This action is not available here.', $this->gridAction(CrudAction::of('nuke', 'Nuke', MarkGadgetsFixture::class)->onRows(), 'row', ['gad-1'])->message);

        $this->db->rows = [];
        self::assertSame('These gadgets no longer exist.', $this->gridAction(CrudAction::delete(), 'row', ['gad-1'])->message);
    }

    #[Test]
    public function a_screen_s_own_action_runs_its_handler_on_the_records(): void
    {
        $this->db->rows = [self::storedGadget()];

        $result = $this->gridAction(CrudAction::of('mark', 'Mark', MarkGadgetsFixture::class)->onRows()->inBulk(), 'row', ['gad-1']);

        self::assertSame('Marked gad-1.', $result->message);
    }

    #[Test]
    public function an_action_reads_in_the_screen_s_words_and_is_checked_at_boot(): void
    {
        $crud = (new GadgetCrudFixture())->crud();
        $delete = CrudAction::delete()->toGridAction($crud);
        self::assertSame(['Delete this gadget? This cannot be undone.', 'Delete {count} gadgets? This cannot be undone.', 'danger'], [$delete->confirm, $delete->confirmBulk, $delete->tone]);
        self::assertSame('gadgets.edit', CrudAction::of('mark', 'Mark', MarkGadgetsFixture::class)->onRows()->permissionOn($crud));
        self::assertSame('shop.publish', CrudAction::of('mark', 'Mark', MarkGadgetsFixture::class)->requires('shop.publish')->permissionOn($crud));

        CrudActionHandlers::reset();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a #[AsCrudAction] class');
        CrudScreens::discover([GadgetCrudFixture::class]);
    }

    #[Test]
    public function a_count_widget_counts_the_screen_and_compares_this_week_with_last(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $this->db->countRows = [['__c' => 42]];
        $this->db->dayRows = [
            ['__d' => $today->modify('-10 days')->format('Y-m-d'), '__c' => 2], // last week
            ['__d' => $today->modify('-1 day')->format('Y-m-d'), '__c' => 3],   // this week
            ['__d' => $today->format('Y-m-d'), '__c' => 3],
        ];
        $widget = $this->action(GadgetCountFixture::class);

        $view = $widget->widget();

        self::assertSame(['stat', '42', '+200%', 'up'], [$view->kind, $view->props['value'], $view->props['delta'], $view->props['trend']]);
        self::assertCount(14, $view->props['points']);
        self::assertSame(['label' => $today->format('M j'), 'value' => 3], $view->props['points'][13]);
        self::assertSame('8 created in the last 14 days', $view->props['caption']);
        self::assertSame(['/crud/gadgets', 'All gadgets'], [$view->href, $view->linkText]);
        self::assertSame('gadgets.read', GadgetCountFixture::requiredPermission(), 'shown only to who may read the screen');
        self::assertStringContainsString('`name` LIKE', (string) $this->db->first('SELECT COUNT')['sql'], 'the screen\'s query(), not the whole table');
    }

    // ---------------------------------------------------------------------

    /** @param list<string> $ids */
    private function gridAction(CrudAction $action, string $scope, array $ids): UiGridActionResult
    {
        $grid = $this->action(CrudGridAction::class);
        $gridAction = $action->toGridAction((new GadgetCrudFixture())->crud());

        return $grid->handle(new UiGridActionContext('uci_grid_000000001', $gridAction, $scope, $ids, ['crud' => 'test.gadgets']));
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $user = new class implements AuthenticatableInterface {
            public function getId(): string { return 'u-1'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'u-1'; }
        };
        $auth = new class($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return $this->user === null; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('not used'); }
        };
        $authorizer = new class($permissions) implements AuthorizerInterface {
            /** @param list<string> $granted */
            public function __construct(private array $granted) {}
            public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
            {
                return array_diff($policy->requiredPermissions, $this->granted) === []
                    ? AccessDecision::allow()
                    : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
            }
        };
        UiPermissions::use($auth, $authorizer);
    }

    /** @param array<string, mixed> $values */
    private function save(array $values, ?string $record = null, ?int $version = null): \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult
    {
        $props = ['crud' => 'test.gadgets'] + ($record !== null ? ['record' => $record, 'version' => $version] : []);

        return $this->action(CrudSaveAction::class)->handle($this->context($values, $props));
    }

    private function delete(string $record): \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult
    {
        return $this->action(CrudDeleteAction::class)->handle($this->context([], ['crud' => 'test.gadgets', 'record' => $record]));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function action(string $class): object
    {
        $engine = new AggregateWriteEngine($this->db, new ResourceModelHydrator());
        $db = $this->db;
        $orm = $this->createMock(OrmManager::class);
        $orm->method('getResourceModelMetadataRegistry')->willReturn(ResourceModelMetadataRegistry::default());
        $orm->method('getAggregateWriteEngine')->willReturn($engine);
        $orm->method('query')->willReturnCallback(static fn (string $model): ResourceModelQuery => new ResourceModelQuery($model, $db, new ResourceModelHydrator(), new \Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader($db, new ResourceModelHydrator(), ResourceModelMetadataRegistry::default())));

        $records = new CrudRecords();
        (new \ReflectionProperty($records, 'orm'))->setValue($records, $orm);
        (new \ReflectionProperty($records, 'tenantContextStore'))->setValue($records, $this->createMock(TenantContextStoreInterface::class));
        $action = new $class();
        (new \ReflectionProperty($action, 'records'))->setValue($action, $records);
        if (property_exists($action, 'relations')) {
            $relations = new \Semitexa\Crud\Application\Service\Relation\RelationOptions();
            (new \ReflectionProperty($relations, 'orm'))->setValue($relations, $orm);
            (new \ReflectionProperty($relations, 'tenantContextStore'))->setValue($relations, $this->createMock(TenantContextStoreInterface::class));
            (new \ReflectionProperty($action, 'relations'))->setValue($action, $relations);
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $props
     */
    private function context(array $values, array $props): UiFormSubmitActionContext
    {
        return new UiFormSubmitActionContext('uci_crud_form_0001', CrudSaveAction::NAME, 'ui_evt_x', $values, [], UiFormSubmitResult::fromFieldResults([]), null, $props);
    }

    /** @return array<string, mixed> */
    private static function storedGadget(): array
    {
        return ['id' => 'gad-1', 'name' => 'Gadget', 'sku' => 'g-1', 'price' => '1.00', 'launch_date' => null, 'version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
    }
}

/** Records SQL; answers a SELECT with $rows, an existence probe with $taken. */
final class FakeCrudAdapter implements DatabaseAdapterInterface
{
    /** @var list<array{sql: string, params: array<string|int, mixed>}> */
    public array $executed = [];
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    public bool $taken = false;
    /** @var list<array<string, mixed>>|null */
    public ?array $countRows = null;
    /** @var list<array<string, mixed>>|null */
    public ?array $dayRows = null;

    public function supports(ServerCapability $capability): bool { return false; }
    public function getServerVersion(): string { return 'fake'; }
    public function lastInsertId(): string { return '0'; }
    public function query(string $sql): QueryResult { return $this->execute($sql); }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];
        if ($this->countRows !== null && str_starts_with($sql, 'SELECT COUNT(*)')) {
            return new QueryResult(rows: $this->countRows, rowCount: 1, lastInsertId: '0');
        }
        if ($this->dayRows !== null && str_starts_with($sql, 'SELECT DATE(')) {
            return new QueryResult(rows: $this->dayRows, rowCount: count($this->dayRows), lastInsertId: '0');
        }
        if (str_contains($sql, ':__expected_version')) {
            // the delete's version guard: the stored rows are current
            return new QueryResult(rows: [['1' => 1]], rowCount: 1, lastInsertId: '0');
        }
        if (str_starts_with($sql, 'SELECT 1')) {
            return new QueryResult(rows: $this->taken ? [['1' => 1]] : [], rowCount: $this->taken ? 1 : 0, lastInsertId: '0');
        }
        if (str_starts_with($sql, 'SELECT')) {
            return new QueryResult(rows: $this->rows, rowCount: count($this->rows), lastInsertId: '0');
        }

        return new QueryResult(rows: [], rowCount: 1, lastInsertId: '0');
    }

    /** @return array{sql: string, params: array<string|int, mixed>}|null */
    public function last(string $verb): ?array
    {
        foreach (array_reverse($this->executed) as $statement) {
            if (str_starts_with($statement['sql'], $verb)) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * The last INSERT / UPDATE as column → value.
     *
     * @return array<string, mixed>
     */
    public function columns(string $verb): array
    {
        $statement = $this->last($verb) ?? throw new \LogicException('no ' . $verb);
        $values = [];
        if ($verb === 'INSERT' && preg_match('/\(([^)]*)\) VALUES \(([^)]*)\)/', $statement['sql'], $m) === 1) {
            foreach (array_map('trim', explode(',', $m[1])) as $i => $column) {
                $values[trim($column, '`')] = $statement['params'][ltrim(trim(explode(',', $m[2])[$i]), ':')] ?? null;
            }
        }
        if ($verb === 'UPDATE' && preg_match('/ SET (.*?) WHERE /', $statement['sql'], $m) === 1) {
            preg_match_all('/`(\w+)` = :(\w+)/', $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $column, $param]) {
                $values[$column] = $statement['params'][$param] ?? null;
            }
        }

        return $values;
    }

    /** @return array{sql: string, params: array<string|int, mixed>}|null */
    public function first(string $verb): ?array
    {
        foreach ($this->executed as $statement) {
            if (str_starts_with($statement['sql'], $verb)) {
                return $statement;
            }
        }

        return null;
    }
}

#[FromTable(name: 'crud_gadgets')]
#[Index(columns: ['sku'], unique: true)]
#[TenantExempt(reason: 'test fixture')]
final readonly class CrudGadgetModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id = '',
        #[Column(type: MySqlType::Varchar, length: 100)]
        public string $name = '',
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $sku = '',
        #[Column(type: MySqlType::Decimal, precision: 10, scale: 2)]
        public string $price = '0.00',
        #[Column(type: MySqlType::Date, nullable: true)]
        public ?\DateTimeImmutable $launch_date = null,
        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version = 1,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $created_at = null,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updated_at = null,
    ) {}
}

#[AsCrud(id: 'test.gadgets', path: '/crud/gadgets', model: CrudGadgetModel::class, label: 'Gadget', permission: 'gadgets', nav: 'Shop')]
final class GadgetCrudFixture extends CrudDefinition
{
    public function fields(): array
    {
        return [
            Field::id(),
            Field::text('name')->required()->searchable(),
            Field::slug('sku')->label('SKU')->required(),
            Field::decimal('price')->required(),
            Field::date('launchDate'),
            Field::datetime('updatedAt')->readOnly(),
        ];
    }

    public function actions(): array
    {
        return [CrudAction::delete(), CrudAction::of('mark', 'Mark', MarkGadgetsFixture::class)->onRows()->inBulk()];
    }

    /** Only gadgets whose name has a letter — a narrowed screen. */
    public function query(ResourceModelQuery $query): ResourceModelQuery
    {
        return $query->where(\Semitexa\Orm\Metadata\ColumnRef::for(CrudGadgetModel::class, 'name'), \Semitexa\Orm\Query\Operator::Like, '%');
    }
}

#[AsCrud(id: 'test.gadgets', path: '/crud/gadgets-twin', model: CrudGadgetModel::class, label: 'Gadget')]
final class GadgetTwinCrudFixture extends CrudDefinition
{
    public function fields(): array { return [Field::id()]; }
}

#[FromTable(name: 'crud_parts')]
#[TenantExempt(reason: 'test fixture')]
final readonly class CrudPartModel
{
    /** @param list<CrudGadgetModel> $children */
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id = '',
        #[Column(type: MySqlType::Varchar, length: 100)]
        public string $name = '',
        #[HasMany(target: CrudGadgetModel::class, foreignKey: 'name', writePolicy: RelationWritePolicy::CascadeOwned)]
        public array $children = [],
    ) {}
}

#[AsCrud(id: 'test.parts', path: '/crud/parts', model: CrudPartModel::class, label: 'Part', nav: 'Workshop')]
final class PartCrudFixture extends CrudDefinition
{
    public function fields(): array { return [Field::id(), Field::text('name')->required()]; }
}

#[AsCrudAction]
final class MarkGadgetsFixture implements CrudActionHandlerInterface
{
    public function run(CrudDefinition $screen, array $records): UiGridActionResult
    {
        return UiGridActionResult::done('Marked ' . implode(', ', array_map(static fn (object $r): string => $r->id, $records)) . '.', count($records));
    }
}

#[\Semitexa\PlatformUi\Attribute\AsDashboardWidget(dashboard: 'test')]
final class GadgetCountFixture extends \Semitexa\Crud\Application\Service\Dashboard\RecordCountWidget
{
    public static function screen(): string
    {
        return GadgetCrudFixture::class;
    }
}
