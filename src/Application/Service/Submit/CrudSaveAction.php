<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Submit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Every screen's create and edit form. The field rules have already passed on
 * the server (FormComponent validates the signed rules before an action
 * runs); this casts each writable field to what is stored, asks the model's
 * unique indexes and the screen's check(), and writes.
 */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class CrudSaveAction extends CrudFormAction implements UiFormSubmitActionInterface
{
    public const NAME = 'crud.save';

    #[InjectAsReadonly]
    protected RelationOptions $relations;

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        $creating = ($context->props['record'] ?? null) === null;
        $target = $this->target($context, $creating ? 'create' : 'edit');
        if ($target instanceof UiFormSubmitActionResult) {
            return $target;
        }
        [$screen, $record] = $target;
        $label = $screen->crud()->label;

        // The writable fields only, cast to what is stored; a field the form did
        // not show is not written.
        $values = (new UiFieldSet($screen->fields()))->cast($context->values);
        $errors = RelationOptions::unknownChoices($this->relations->resolve($screen->crud()->model(), $screen->fields()), $values)
            + $this->records->takenValues($screen, $values, $record)
            + $screen->check($values, $record);
        if ($errors !== []) {
            return UiFormSubmitActionResult::rejected('Not saved — fix the highlighted fields.')->withFieldErrors($errors);
        }

        $version = $context->props['version'] ?? null;
        try {
            $this->records->save($screen, $record, $values, is_int($version) ? $version : null);
        } catch (StaleAggregateException) {
            return UiFormSubmitActionResult::rejected(sprintf('Not saved: someone else changed this %s after you opened it. Reload to see their version.', mb_strtolower($label)));
        } catch (ConstraintViolationException) {
            return UiFormSubmitActionResult::rejected(sprintf('Not saved: this %s conflicts with a stored record.', mb_strtolower($label)));
        }

        $done = sprintf($creating ? '%s created.' : '%s saved.', $label);
        $result = UiFormSubmitActionResult::accepted($done, extraPatches: [UiResponsePatch::toast($context->formInstanceId, $done, 'success')])
            ->closingModal();

        // A create form stays on the page for the next record; an edit form
        // was rendered for this record and goes away with its dialog.
        return $creating ? $result->resettingForm() : $result;
    }
}
