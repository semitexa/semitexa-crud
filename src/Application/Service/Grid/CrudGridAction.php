<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Grid;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlers;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Grid\UiGridActionInterface;
use Semitexa\PlatformUi\Attribute\AsGridAction;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionContext;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;

/**
 * Every CRUD screen's grid actions. The grid has already let through only an
 * action it was rendered with, in a scope it offers; this takes the screen
 * from the grid's signed props, the action from the SCREEN (not from what the
 * page sent), checks the visitor's permission for it, and finds the records
 * through the screen's query() — an id the screen does not hold is skipped,
 * never acted on.
 */
#[AsService]
#[ExecutionScoped]
#[AsGridAction(self::NAME)]
final class CrudGridAction implements UiGridActionInterface
{
    public const NAME = 'crud.run';

    #[InjectAsReadonly]
    protected CrudRecords $records;

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiGridActionContext $context): UiGridActionResult
    {
        $screenId = $context->props['crud'] ?? null;
        $screen = is_string($screenId) ? CrudScreens::get($screenId) : null;
        $action = null;
        foreach ($screen?->actions() ?? [] as $candidate) {
            if ($candidate->id === $context->action->id && in_array($context->scope, $candidate->scopes, true)) {
                $action = $candidate;
            }
        }
        if ($screen === null || $action === null) {
            return UiGridActionResult::refused('This action is not available here.');
        }
        $crud = $screen->crud();
        if (!UiPermissions::permits($crud->permissionFor('read')) || !UiPermissions::permits($action->permissionOn($crud))) {
            return UiGridActionResult::refused(sprintf('You may not %s %s.', mb_strtolower($action->label), mb_strtolower($crud->plural())));
        }

        $records = $this->records->findMany($screen, $context->ids);
        if ($context->ids !== [] && $records === []) {
            return UiGridActionResult::refused(sprintf('These %s no longer exist.', mb_strtolower($crud->plural())));
        }

        if ($action->handler === null) {
            return $this->delete($screen, $records, count($context->ids));
        }
        $handler = CrudActionHandlers::get($action->handler)
            ?? throw new \LogicException(sprintf('%s is not a #[AsCrudAction] class.', $action->handler));

        return $handler->run($screen, $records);
    }

    /**
     * Each record is its own write: one that cannot go (changed meanwhile,
     * still referred to) is reported, and the others are deleted.
     *
     * @param list<object> $records
     */
    private function delete(CrudDefinition $screen, array $records, int $asked): UiGridActionResult
    {
        $crud = $screen->crud();
        $deleted = 0;
        foreach ($records as $record) {
            try {
                $this->records->delete($screen, $record);
                $deleted++;
            } catch (StaleAggregateException|ConstraintViolationException) {
            }
        }
        $noun = static fn (int $n): string => mb_strtolower($n === 1 ? $crud->label : $crud->plural());
        if ($deleted === 0) {
            return UiGridActionResult::refused(sprintf('Nothing was deleted: the %s changed meanwhile or other records still refer to them.', $noun($asked)));
        }

        return UiGridActionResult::done(
            $deleted === $asked
                ? sprintf('Deleted %d %s.', $deleted, $noun($deleted))
                : sprintf('Deleted %d of %d %s; the others changed meanwhile, are referred to, or no longer exist.', $deleted, $asked, $noun($asked)),
            $deleted,
        );
    }
}
