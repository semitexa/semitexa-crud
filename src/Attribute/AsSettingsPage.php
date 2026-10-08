<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Contract\DeclaresRequiredPermissionsInterface;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Crud\Application\Resource\Response\SettingsPageResponse;

/**
 * A settings page in one class: its fields, stored per tenant in
 * semitexa/platform-settings under the page's id, edited on the platform
 * settings layout. Pages of one `group` are the sections of one settings area.
 *
 *     #[AsSettingsPage(id: 'shop.store', path: '/admin/settings/store', title: 'Store',
 *                      group: 'shop', permission: 'settings.manage')]
 *     final class StoreSettings extends SettingsDefinition
 *     {
 *         public function fields(): array { return [Field::text('storeName')->required()->default('My shop'), …]; }
 *     }
 *
 * Read a value anywhere with SettingsValues::of(StoreSettings::class)['storeName'].
 */
#[Capability(
    id: 'crud.settings-page',
    summary: 'A settings page declared as one class with a field list: stored per tenant, validated by the field rules, rendered on the platform settings layout, read back typed with defaults.',
    useWhen: 'An application needs editable configuration - names, thresholds, switches - that an administrator changes at runtime.',
    avoidWhen: 'The value is per visitor (a profile, a preference) - store it per user; or it is deployment configuration - use .env.',
    replaces: [
        'a settings page, handler, form action and store calls per group of options',
        'reading a setting with its default repeated at every call site',
    ],
    seeAlso: 'crud.screen',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSettingsPage extends AbstractPayloadRoute implements DeclaresRequiredPermissionsInterface
{
    /**
     * @param string  $id         where the values are stored (the settings module key) and the route name
     * @param string  $group      the settings area this page is a section of
     * @param ?string $permission needed to see and change the page; null = signed in
     * @param ?string $nav        the navigation group to list the page under; null = not listed
     */
    public function __construct(
        public readonly string $id,
        string $path,
        public readonly string $title,
        public readonly string $group = 'settings',
        public readonly ?string $description = null,
        public readonly ?string $permission = null,
        public readonly ?string $nav = null,
        public readonly string $icon = 'settings',
        public readonly int $order = 100,
        public readonly string $layout = '@platform-ui/layouts/app-shell.html.twig',
        ?string $doc = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+\z/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('#[AsSettingsPage] id "%s" must be dotted lowercase words, like "shop.store".', $id));
        }

        parent::__construct(
            doc: $doc,
            path: $path,
            methods: ['GET'],
            name: $id,
            responseWith: SettingsPageResponse::class,
            renderProfile: RenderProfile::Html,
        );
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Protected;
    }

    public function requiredPermissions(string $payloadClass): array
    {
        return $this->permission === null ? [] : [$this->permission];
    }
}
