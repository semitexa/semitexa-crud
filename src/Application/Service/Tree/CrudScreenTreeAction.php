<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Tree;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlers;
use Semitexa\Crud\Attribute\AsCrudAction;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeActionKindInterface;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * A tree action that opens a CRUD screen's dialog (ep-platform-ai-ui): the
 * screen must exist and the visitor must hold the operation's permission —
 * an agent cannot put a "New product" button before someone who may not
 * create products.
 */
abstract class CrudScreenTreeAction implements UiTreeActionKindInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** create | edit */
    abstract protected function operation(): string;

    public function check(array $action, string $path): array
    {
        $this->ensureScreens();
        $id = $action['screen'] ?? null;
        $screen = is_string($id) ? CrudScreens::get($id) : null;
        if ($screen === null) {
            return [new UiTreeError('tree.action_screen', $path . '/screen', 'The action names no CRUD screen.', $this->screensForVisitor(), UiTreeError::describe($id))];
        }
        if (!UiPermissions::permits($screen->crud()->permissionFor($this->operation()))) {
            return [new UiTreeError('tree.action_forbidden', $path, sprintf('This visitor may not %s %s.', $this->operation(), mb_strtolower($screen->crud()->plural())), 'an action this visitor may run', (string) $id, 'Leave the action, and the control that runs it, out of this screen.')];
        }
        if ($this->operation() === 'edit' && (!is_scalar($action['record'] ?? null) || (string) $action['record'] === '')) {
            return [new UiTreeError('tree.action_record', $path . '/record', 'An edit action names the record it opens.', '{"kind": "edit", "screen": "…", "record": "<id>"}', UiTreeError::describe($action['record'] ?? null))];
        }

        return [];
    }

    public function propValue(array $action): string
    {
        $this->ensureScreens();
        $screen = CrudScreens::get((string) ($action['screen'] ?? ''));
        if ($screen === null) {
            return '';
        }

        return $this->operation() === 'create'
            ? $screen->crud()->path . '?create'
            : $screen->crud()->path . '?edit=' . rawurlencode((string) $action['record']);
    }

    /** The ids of the screens this visitor may run the operation on — never one they may not. */
    protected function screensForVisitor(): string
    {
        $this->ensureScreens();
        $ids = [];
        foreach (CrudScreens::all() as $id => $crud) {
            if (UiPermissions::permits($crud->permissionFor($this->operation()))) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? 'none for this visitor' : implode(', ', $ids);
    }

    /** A worker finds the screens at boot; a command (ui:tree:validate) has no boot, so they are found here. */
    private function ensureScreens(): void
    {
        if (CrudScreens::all() === [] && isset($this->classDiscovery, $this->container)) {
            // The order the boot listener uses: a screen's actions must be known first.
            CrudActionHandlers::discover(
                $this->classDiscovery->findClassesWithAttribute(AsCrudAction::class),
                fn (string $class): object => RequestScopedContainer::forCurrentExecution($this->container)->get($class),
            );
            CrudScreens::discover($this->classDiscovery->findClassesWithAttribute(AsCrud::class));
        }
    }
}
