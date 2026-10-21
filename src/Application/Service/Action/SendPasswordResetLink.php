<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Action;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Attribute\AsCrudAction;
use Semitexa\Platform\User\Application\Service\PasswordResetService;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;

/**
 * PlatformUsersCrud's row action: a password-reset link to the user's own
 * address, by the same PasswordResetService and limits as the "forgot
 * password" form. The administrator never sees the link.
 */
#[AsService]
#[AsCrudAction]
final class SendPasswordResetLink implements CrudActionHandlerInterface
{
    #[InjectAsReadonly]
    protected PasswordResetService $resets;

    public function run(CrudDefinition $screen, array $records): UiGridActionResult
    {
        $sent = 0;
        foreach ($records as $record) {
            try {
                $this->resets->request((string) $record->email, (string) $record->tenantId);
                $sent++;
            } catch (\LogicException) {
                return UiGridActionResult::refused('Password reset has no mailer: set PLATFORM_USER_RESET_URL.');
            }
        }

        return UiGridActionResult::done($sent === 1 ? 'A reset link is on its way.' : sprintf('Reset links are on their way to %d users.', $sent), $sent);
    }
}
