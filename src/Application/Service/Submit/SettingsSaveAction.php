<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Submit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Crud\Application\Service\Settings\SettingsValues;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * Every settings page's form. The page comes from the form's SIGNED props
 * (`settings`), the permission is checked for the visitor submitting (a
 * submit arrives through HUG, not through the page's protected route), the
 * field rules have already passed on the server, and only the page's own
 * fields are stored.
 */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class SettingsSaveAction implements UiFormSubmitActionInterface
{
    public const NAME = 'settings.save';

    #[InjectAsReadonly]
    protected SettingsValues $values;

    public function name(): string
    {
        return self::NAME;
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        $id = $context->props['settings'] ?? null;
        $definition = is_string($id) ? SettingsPages::get($id) : null;
        if ($definition === null) {
            return UiFormSubmitActionResult::rejected('This form does not name a settings page.');
        }
        $page = $definition->page();
        if (!UiPermissions::permits($page->permission)) {
            return UiFormSubmitActionResult::rejected(sprintf('You may not change the %s settings.', mb_strtolower($page->title)));
        }

        $values = (new UiFieldSet($definition->fields()))->cast($context->values);
        $errors = $definition->check($values);
        if ($errors !== []) {
            return UiFormSubmitActionResult::rejected('Not saved — fix the highlighted fields.')->withFieldErrors($errors);
        }
        $this->values->save($definition, $values);

        return UiFormSubmitActionResult::accepted(sprintf('%s settings saved.', $page->title));
    }
}
