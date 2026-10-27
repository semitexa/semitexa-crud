<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Api\Application\Service\Collection\CollectionFeedSupport;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Crud\Application\Handler\PayloadHandler\CollectionFeedHandler;
use Semitexa\Crud\Application\Payload\Request\PlatformUsersCrud;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\CollectionQueryCompiler;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\Platform\User\Application\Db\MySQL\Model\PlatformUserResourceModel;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

/**
 * The users screen decides what it holds by who is signed in: an owner every
 * user, anyone else their own site's (owners left out), a stranger nothing —
 * never by the site a request names, which one session can change at will.
 */
final class PlatformUsersCrudTest extends TestCase
{
    private OrmManager $orm;

    /** @var array<string, array{id: string, tenant: string, role: string}> */
    private array $users = [];

    /** @var array{auth: mixed, authorizer: mixed} UiPermissions as it was before the test. */
    private array $permissions;

    protected function setUp(): void
    {
        $this->permissions = ['auth' => self::permission('auth')->getValue(), 'authorizer' => self::permission('authorizer')->getValue()];
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE platform_user (
            id BLOB PRIMARY KEY, email VARCHAR(255) NOT NULL, password_hash TEXT NOT NULL, display_name VARCHAR(255) NOT NULL,
            tenant_id VARCHAR(64) NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,
            updated_at DATETIME NULL, last_login_at DATETIME NULL, failed_attempts INT NOT NULL DEFAULT 0, locked_until DATETIME NULL,
            password_issued_by_operator INT NOT NULL DEFAULT 0, credential_version INT NOT NULL DEFAULT 0, row_version INT NOT NULL DEFAULT 0)');
        foreach ([
            'ada@museum.test' => ['museum', 'admin'],
            'eve@museum.test' => ['museum', 'editor'],
            'bob@clinic.test' => ['clinic', 'admin'],
            'owner@example.test' => ['', 'owner'],
            'boss@museum.test' => ['museum', 'owner'],
        ] as $email => [$tenant, $role]) {
            $id = Uuid7::generate();
            $this->users[$email] = ['id' => $id, 'tenant' => $tenant, 'role' => $role];
            $this->orm->getAdapter()->execute(
                "INSERT INTO platform_user (id, email, password_hash, display_name, tenant_id, role, status, created_at) VALUES (:id, :email, 'x', '', :tenant, :role, 'active', '2026-10-21 00:00:00')",
                ['id' => Uuid7::toBytes($id), 'email' => $email, 'tenant' => $tenant, 'role' => $role],
            );
        }
    }

    protected function tearDown(): void
    {
        self::permission('auth')->setValue(null, $this->permissions['auth']);
        self::permission('authorizer')->setValue(null, $this->permissions['authorizer']);
        $this->orm->shutdown();
    }

    #[Test]
    public function an_owner_holds_every_user(): void
    {
        $this->signIn('owner@example.test');

        self::assertCount(5, $this->records()->scoped(new TestUsersCrud())->fetchAll());
    }

    #[Test]
    public function a_site_s_admin_holds_and_opens_only_that_site_s_users_owners_left_out(): void
    {
        $this->signIn('ada@museum.test');
        $records = $this->records();
        $screen = new TestUsersCrud();

        self::assertSame(['ada@museum.test', 'eve@museum.test'], self::emails($records->scoped($screen)->fetchAll()));
        self::assertNull($records->find($screen, $this->users['bob@clinic.test']['id']), 'another site\'s user cannot be opened by id');
        self::assertNull($records->find($screen, $this->users['boss@museum.test']['id']), 'nor an owner bound to her site');
        self::assertSame([], $records->findMany($screen, [$this->users['owner@example.test']['id'], $this->users['bob@clinic.test']['id']]), 'nor reached by a row action');
    }

    #[Test]
    public function a_stranger_holds_nobody(): void
    {
        self::assertSame([], $this->records()->scoped(new TestUsersCrud())->fetchAll(), 'no visitor');

        UiPermissions::use(self::auth(new class implements AuthenticatableInterface {
            public function getId(): string { return 'google:someone'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'google:someone'; }
        }), null);
        self::assertSame([], $this->records()->scoped(new TestUsersCrud())->fetchAll(), 'not a platform-user account');
    }

    /** The grid reads through the feed handler, not CrudRecords: the same narrowing there. */
    #[Test]
    public function the_grid_feed_lists_only_the_visitor_s_site(): void
    {
        $this->signIn('bob@clinic.test');
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

        $response = (new \ReflectionMethod(CollectionFeedHandler::class, 'buildCollectionResponse'))->invoke($handler, new TestUsersCrud(), new CollectionFeedJsonResponse());

        self::assertSame(['bob@clinic.test'], array_column(json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'], 'email'));
    }

    #[Test]
    public function nobody_climbs_above_their_own_role_or_changes_their_own(): void
    {
        $screen = new TestUsersCrud();

        $this->signIn('ada@museum.test');
        self::assertArrayHasKey('role', $screen->check(['role' => 'owner'], $this->row('eve@museum.test')), 'an admin makes no owner');
        self::assertArrayHasKey('role', $screen->check(['role' => 'editor', 'status' => 'active'], $this->row('ada@museum.test')), 'nor demotes herself');
        self::assertArrayHasKey('role', $screen->check(['role' => 'admin', 'status' => 'disabled'], $this->row('ada@museum.test')), 'nor switches herself off');
        self::assertSame([], $screen->check(['role' => 'admin', 'status' => 'active'], $this->row('eve@museum.test')), 'an admin makes an admin');

        $this->signIn('eve@museum.test');
        self::assertArrayHasKey('role', $screen->check(['role' => 'admin', 'status' => 'active'], $this->row('eve@museum.test')));
        self::assertArrayHasKey('role', $screen->check(['role' => 'editor', 'status' => 'disabled'], $this->row('ada@museum.test')), 'an editor cannot touch an admin');

        $this->signIn('owner@example.test');
        self::assertArrayHasKey('role', $screen->check(['role' => 'owner', 'status' => 'active'], $this->row('ada@museum.test')), 'an owner belongs to no site');
        self::assertSame([], $screen->check(['role' => 'admin', 'status' => 'disabled'], $this->row('ada@museum.test')));
        // An owner bound to a site from an older install: refused every save, a new name included.
        self::assertSame([], $screen->check(['role' => 'owner', 'status' => 'active'], $this->row('boss@museum.test')), 'its role unchanged, it can be saved');
    }

    #[Test]
    public function only_an_owner_or_an_admin_changes_users(): void
    {
        $screen = new TestUsersCrud();
        self::assertArrayHasKey('role', $screen->check(['role' => 'editor', 'status' => 'active'], $this->row('eve@museum.test')), 'no visitor');

        $this->signIn('eve@museum.test');
        self::assertArrayHasKey('role', $screen->check(['role' => 'editor', 'status' => 'disabled'], $this->row('eve@museum.test')), 'an editor, even with users.edit');
    }

    #[Test]
    public function accounts_are_neither_made_nor_deleted_here(): void
    {
        $this->signIn('owner@example.test');
        $screen = new TestUsersCrud();

        self::assertFalse($screen->allows('create'));
        self::assertFalse($screen->allows('delete'));
        self::assertTrue($screen->allows('edit'));
        self::assertArrayHasKey('email', $screen->check(['role' => 'editor'], null));
    }

    /** The form, its save and the reset-link row action all ask allows('edit'). */
    #[Test]
    public function editing_is_offered_only_to_someone_who_may_change_users(): void
    {
        $screen = new TestUsersCrud();
        self::assertFalse($screen->allows('edit'), 'no visitor');

        $this->signIn('eve@museum.test');
        self::assertFalse($screen->allows('edit'), 'an editor, even with users.edit');

        $this->signIn('ada@museum.test');
        self::assertTrue($screen->allows('edit'), 'an admin');
        self::assertFalse($screen->allows('delete'));
    }

    private static function permission(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(UiPermissions::class, $name);
    }

    private function signIn(string $email): void
    {
        $u = $this->users[$email];
        UiPermissions::use(self::auth(new UserPrincipal(new PlatformUser(id: $u['id'], email: $email, passwordHash: 'x', tenantId: $u['tenant'], role: UserRole::from($u['role'])))), null);
    }

    private function row(string $email): object
    {
        $u = $this->users[$email];

        return (object) ['id' => $u['id'], 'email' => $email, 'tenantId' => $u['tenant'], 'role' => $u['role'], 'status' => 'active'];
    }

    private function records(): CrudRecords
    {
        $records = new CrudRecords();
        (new \ReflectionProperty($records, 'orm'))->setValue($records, $this->orm);
        (new \ReflectionProperty($records, 'tenantContextStore'))->setValue($records, $this->createMock(TenantContextStoreInterface::class));

        return $records;
    }

    private static function auth(AuthenticatableInterface $user): AuthContextInterface
    {
        return new class ($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return $this->user === null; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?self { return null; }
            public static function getOrFail(): self { throw new \LogicException('not in tests'); }
        };
    }

    /**
     * @param list<object> $users
     * @return list<string>
     */
    private static function emails(array $users): array
    {
        $emails = array_map(static fn (object $u): string => $u->email, $users);
        sort($emails);

        return $emails;
    }
}

#[AsCrud(id: 'test.users', path: '/test/users', model: PlatformUserResourceModel::class, label: 'User', permission: 'users')]
final class TestUsersCrud extends PlatformUsersCrud
{
}
