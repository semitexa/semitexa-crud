<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Twig;

use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class CrudTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * crud_nav(appNav = [])
         *
         * The app shell's navigation with every CRUD screen and settings page
         * that names a `nav` group added to it — into the group of that label, or a new group
         * after the others. Each item carries the screen's read permission;
         * the app shell lists it only for a visitor who holds it:
         *
         *     {% set appNav = crud_nav([{label: 'Lab', items: […]}]) %}
         */
        TwigExtensionRegistry::registerFunction('crud_nav', self::nav(...));
    }

    /**
     * @param list<array{label: string, items: list<array<string, mixed>>}> $nav
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function nav(array $nav = []): array
    {
        foreach (CrudScreens::all() as $crud) {
            if ($crud->nav === null) {
                continue;
            }
            $item = ['label' => $crud->plural(), 'href' => (string) $crud->path, 'icon' => $crud->icon, 'match' => 'prefix', 'permission' => $crud->permissionFor('read')];
            $group = array_search($crud->nav, array_column($nav, 'label'), true);
            if ($group === false) {
                $nav[] = ['label' => $crud->nav, 'items' => [$item]];
                continue;
            }
            $nav[$group]['items'][] = $item;
        }
        foreach (SettingsPages::all() as $page) {
            if ($page->nav === null) {
                continue;
            }
            $item = ['label' => $page->title . ' settings', 'href' => (string) $page->path, 'icon' => $page->icon, 'match' => 'exact', 'permission' => $page->permission];
            $group = array_search($page->nav, array_column($nav, 'label'), true);
            if ($group === false) {
                $nav[] = ['label' => $page->nav, 'items' => [$item]];
                continue;
            }
            $nav[$group]['items'][] = $item;
        }

        return $nav;
    }
}
