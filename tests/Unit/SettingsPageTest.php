<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\Crud\Application\Payload\Request\SettingsDefinition;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Crud\Application\Service\Settings\SettingsValues;
use Semitexa\Crud\Application\Service\Submit\SettingsSaveAction;
use Semitexa\Crud\Application\Service\Twig\CrudTwigExtension;
use Semitexa\Crud\Attribute\AsSettingsPage;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * tk-rs-settings: a settings page is its fields — read back with their
 * defaults, saved only for the page's own fields, behind its permission, with
 * its own rules answering on the field.
 */
final class SettingsPageTest extends TestCase
{
    private MemorySettingsStore $store;

    protected function setUp(): void
    {
        SettingsPages::discover([ShopSettingsFixture::class, MailSettingsFixture::class]);
        $this->store = new MemorySettingsStore();
        $this->grant(['shop.settings']);
    }

    protected function tearDown(): void
    {
        SettingsPages::reset();
        UiPermissions::reset();
    }

    #[Test]
    public function a_page_is_a_protected_route_and_part_of_its_group(): void
    {
        $page = (new ShopSettingsFixture())->page();
        self::assertSame(PayloadAccessType::Protected, $page->getAccessType());
        self::assertSame(['shop.settings'], $page->requiredPermissions(ShopSettingsFixture::class));
        self::assertSame(['Shop', 'Mail'], array_map(static fn (AsSettingsPage $p): string => $p->title, SettingsPages::inGroup('shop')));

        $nav = CrudTwigExtension::nav([]);
        self::assertSame(['label' => 'Admin', 'items' => [['label' => 'Shop settings', 'href' => '/settings/shop', 'icon' => 'settings', 'match' => 'exact', 'permission' => 'shop.settings']]], $nav[0]);

        $this->expectException(\LogicException::class);
        SettingsPages::discover([ShopSettingsFixture::class, ShopTwinSettingsFixture::class]);
    }

    #[Test]
    public function a_value_is_what_was_saved_else_the_field_default(): void
    {
        $this->store->rows['test.shop'] = ['name' => 'Corner shop'];

        self::assertSame(['name' => 'Corner shop', 'threshold' => 5, 'opensAt' => null], $this->values()->of(ShopSettingsFixture::class));
    }

    #[Test]
    public function saving_stores_the_cast_fields_of_the_page_and_nothing_else(): void
    {
        $result = $this->save(['name' => '  Kiosk ', 'threshold' => '9', 'opensAt' => '2026-10-06T08:00', 'smuggled' => 'x']);

        self::assertTrue($result->accepted, $result->message);
        self::assertSame('Shop settings saved.', $result->message);
        self::assertSame(['name' => 'Kiosk', 'threshold' => 9, 'opensAt' => '2026-10-06 08:00:00'], $this->store->rows['test.shop']);
    }

    #[Test]
    public function the_permission_and_the_page_s_own_rule_are_checked_on_save(): void
    {
        $this->grant([]);
        self::assertSame('You may not change the shop settings.', $this->save(['name' => 'x', 'threshold' => '1'])->message);
        self::assertArrayNotHasKey('test.shop', $this->store->rows);

        $this->grant(['shop.settings']);
        $result = $this->save(['name' => 'x', 'threshold' => '0']);
        self::assertSame(['threshold' => 'Zero would mark everything as low.'], $result->fieldErrors);

        $context = new UiFormSubmitActionContext('uci_settings_0001', SettingsSaveAction::NAME, 'ui_evt_x', [], [], UiFormSubmitResult::fromFieldResults([]), null, ['settings' => 'test.nothing']);
        self::assertSame('This form does not name a settings page.', $this->action()->handle($context)->message);
    }

    /** @param array<string, mixed> $values */
    private function save(array $values): \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult
    {
        return $this->action()->handle(new UiFormSubmitActionContext('uci_settings_0001', SettingsSaveAction::NAME, 'ui_evt_x', $values, [], UiFormSubmitResult::fromFieldResults([]), null, ['settings' => 'test.shop']));
    }

    private function action(): SettingsSaveAction
    {
        $action = new SettingsSaveAction();
        (new \ReflectionProperty($action, 'values'))->setValue($action, $this->values());

        return $action;
    }

    private function values(): SettingsValues
    {
        $values = new SettingsValues();
        (new \ReflectionProperty($values, 'store'))->setValue($values, $this->store);

        return $values;
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $user = new class implements AuthenticatableInterface {
            public function getId(): string { return 'u'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'u'; }
        };
        $auth = new class($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return false; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('not used'); }
        };
        UiPermissions::use($auth, new class($permissions) implements AuthorizerInterface {
            /** @param list<string> $granted */
            public function __construct(private array $granted) {}
            public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
            {
                return array_diff($policy->requiredPermissions, $this->granted) === [] ? AccessDecision::allow() : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
            }
        });
    }
}

/** The module-scoped half of the store, in memory. */
final class MemorySettingsStore implements SettingsStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function get(string $moduleKey, string $key): mixed { return $this->rows[$moduleKey][$key] ?? null; }
    public function set(string $moduleKey, string $key, mixed $value): void { $this->rows[$moduleKey][$key] = $value; }
    public function getAll(string $moduleKey): array { return $this->rows[$moduleKey] ?? []; }
    public function remove(string $moduleKey, string $key): void { unset($this->rows[$moduleKey][$key]); }
    public function has(string $moduleKey, string $key): bool { return isset($this->rows[$moduleKey][$key]); }
    public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool { return false; }
    public function getForUser(string $moduleKey, string $key, string $userId): mixed { return null; }
    public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void {}
    public function getAllForUser(string $moduleKey, string $userId): array { return []; }
    public function removeForUser(string $moduleKey, string $key, string $userId): void {}
    public function hasForUser(string $moduleKey, string $key, string $userId): bool { return false; }
}

#[AsSettingsPage(id: 'test.shop', path: '/settings/shop', title: 'Shop', group: 'shop', permission: 'shop.settings', nav: 'Admin', order: 1)]
final class ShopSettingsFixture extends SettingsDefinition
{
    public function fields(): array
    {
        return [
            Field::text('name')->required(),
            Field::integer('threshold')->default(5),
            Field::datetime('opensAt'),
        ];
    }

    public function check(array $values): array
    {
        return $values['threshold'] === 0 ? ['threshold' => 'Zero would mark everything as low.'] : [];
    }
}

#[AsSettingsPage(id: 'test.mail', path: '/settings/mail', title: 'Mail', group: 'shop', order: 2)]
final class MailSettingsFixture extends SettingsDefinition
{
    public function fields(): array { return [Field::email('from')]; }
}

#[AsSettingsPage(id: 'test.shop', path: '/settings/shop-twin', title: 'Twin')]
final class ShopTwinSettingsFixture extends SettingsDefinition
{
    public function fields(): array { return []; }
}
