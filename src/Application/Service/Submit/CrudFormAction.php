<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Submit;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * What crud.save and crud.delete share: the screen and the record come from
 * the form's SIGNED props (`crud`, `record`) — a browser can neither point a
 * form at another screen nor retarget it to another record — and the
 * permission is checked here, for the visitor submitting, because a submit
 * arrives through HUG, not through the screen's protected route.
 */
abstract class CrudFormAction
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    /** @return array{0: CrudDefinition, 1: ?object}|UiFormSubmitActionResult the screen and the record (null: none named) */
    protected function target(UiFormSubmitActionContext $context, string $operation): array|UiFormSubmitActionResult
    {
        $id = $context->props['crud'] ?? null;
        $screen = is_string($id) ? CrudScreens::get($id) : null;
        if ($screen === null) {
            return UiFormSubmitActionResult::rejected('This form does not name a screen it may write.');
        }
        $crud = $screen->crud();
        if (!UiPermissions::permits($crud->permissionFor('read')) || !UiPermissions::permits($crud->permissionFor($operation))) {
            return UiFormSubmitActionResult::rejected(sprintf('You may not %s %s.', $operation, mb_strtolower($crud->plural())));
        }

        $recordId = $context->props['record'] ?? null;
        if ($recordId === null) {
            return [$screen, null];
        }
        $record = is_string($recordId) && $recordId !== '' ? $this->records->find($screen, $recordId) : null;
        if ($record === null) {
            return UiFormSubmitActionResult::rejected(sprintf('This %s no longer exists.', mb_strtolower($crud->label)));
        }

        return [$screen, $record];
    }
}
