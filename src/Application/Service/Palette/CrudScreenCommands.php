<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Palette;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Palette\UiCommandSourceInterface;
use Semitexa\PlatformUi\Attribute\AsCommandSource;
use Semitexa\PlatformUi\Domain\Model\Palette\UiPaletteItem;

/**
 * Ctrl+K: every CRUD screen ("Products") and its create dialog ("New
 * product"), by the screen's names, and every settings page ("Store settings"). The palette drops a command whose
 * permission the visitor lacks.
 *
 * Then the records themselves: from two characters on, each screen the
 * visitor may read is searched by its searchable fields (as its grid's search
 * box would), and each match opens its edit dialog, or its view dialog for a
 * visitor who may not edit.
 */
#[AsService]
#[AsCommandSource]
final class CrudScreenCommands implements UiCommandSourceInterface
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    public function search(string $query, int $limit): iterable
    {
        $needle = mb_strtolower($query);
        $found = 0;
        foreach (CrudScreens::all() as $crud) {
            $names = [mb_strtolower($crud->plural()), mb_strtolower($crud->label), mb_strtolower((string) $crud->nav)];
            if (array_filter($names, static fn (string $name): bool => $name !== '' && str_contains($name, $needle)) === []) {
                continue;
            }
            // Checked here, not left to the palette: a screen without a
            // permission is for signed-in visitors (as its route is), while the
            // palette shows an item without one to everybody, guests included.
            if (!UiPermissions::permits($crud->permissionFor('read'))) {
                continue;
            }
            yield new UiPaletteItem(
                title: $crud->plural(),
                href: (string) $crud->path,
                group: 'Screens',
                subtitle: $crud->nav ?? '',
                icon: $crud->icon,
                permission: $crud->permissionFor('read'),
            );
            $found++;
            if (UiPermissions::permits($crud->permissionFor('create'))) {
                yield new UiPaletteItem(
                    title: 'New ' . mb_strtolower($crud->label),
                    href: $crud->path . '?create',
                    group: 'Screens',
                    subtitle: $crud->plural(),
                    icon: 'plus',
                    permission: $crud->permissionFor('create'),
                );
                $found++;
            }
            if ($found >= $limit) {
                return;
            }
        }
        foreach (SettingsPages::all() as $page) {
            $names = mb_strtolower($page->title . ' settings ' . (string) $page->description);
            if ($found >= $limit || !str_contains($names, $needle) || !UiPermissions::permits($page->permission)) {
                continue;
            }
            yield new UiPaletteItem(
                title: $page->title . ' settings',
                href: (string) $page->path,
                group: 'Settings',
                subtitle: (string) $page->description,
                icon: $page->icon,
                permission: $page->permission,
            );
            $found++;
        }
        if (mb_strlen($query) < 2) {
            return;
        }
        foreach (CrudScreens::all() as $id => $crud) {
            if ($found >= $limit || !UiPermissions::permits($crud->permissionFor('read'))) {
                continue;
            }
            foreach ($this->records($id, $query, $limit - $found) as $item) {
                yield $item;
                $found++;
            }
        }
    }

    /** @return list<UiPaletteItem> the screen's records whose searchable fields hold the query */
    private function records(string $id, string $query, int $limit): array
    {
        $screen = CrudScreens::get($id);
        if ($screen === null) {
            return [];
        }
        $fields = new UiFieldSet($screen->fields());
        $searched = array_filter(
            array_map($fields->get(...), $fields->searchable()),
            static fn (?UiField $field): bool => $field !== null && $field->type !== 'belongsToMany',
        );
        if ($searched === []) {
            return [];
        }
        $crud = $screen->crud();
        $metadata = $this->records->metadata($screen);
        $columns = array_values(array_map(
            static fn (UiField $field): ColumnRef => ColumnRef::for($crud->model(), CrudRecords::property($field, $metadata)),
            $searched,
        ));

        $verb = UiPermissions::permits($crud->permissionFor('edit')) ? 'edit' : 'view';
        $items = [];
        $records = $this->records->scoped($screen)
            ->whereAnyLike($columns, '%' . addcslashes($query, '\\%_') . '%')
            ->limit($limit)
            ->fetchAll();
        foreach ($records as $record) {
            $recordId = CrudRecords::idOf($record, $metadata);
            $items[] = new UiPaletteItem(
                title: $screen->title($record, $recordId),
                href: $crud->path . '?' . $verb . '=' . rawurlencode($recordId),
                group: $crud->plural(),
                subtitle: $crud->label,
                icon: $crud->icon,
                permission: $crud->permissionFor('read'),
            );
        }

        return $items;
    }
}
