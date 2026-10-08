<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Submit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/** The delete form in a screen's edit dialog. */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class CrudDeleteAction extends CrudFormAction implements UiFormSubmitActionInterface
{
    public const NAME = 'crud.delete';

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        if (($context->props['record'] ?? null) === null) {
            return UiFormSubmitActionResult::rejected('This form does not say which record it deletes.');
        }
        $target = $this->target($context, 'delete');
        if ($target instanceof UiFormSubmitActionResult) {
            return $target;
        }
        [$screen, $record] = $target;
        $label = $screen->crud()->label;

        try {
            $this->records->delete($screen, $record);
        } catch (StaleAggregateException) {
            return UiFormSubmitActionResult::rejected(sprintf('Not deleted: someone else changed this %s after you opened it.', mb_strtolower($label)));
        } catch (ConstraintViolationException) {
            return UiFormSubmitActionResult::rejected(sprintf('Not deleted: other records still refer to this %s.', mb_strtolower($label)));
        }

        $done = sprintf('%s deleted.', $label);

        return UiFormSubmitActionResult::accepted($done, extraPatches: [UiResponsePatch::toast($context->formInstanceId, $done, 'success')])
            ->closingModal();
    }
}
