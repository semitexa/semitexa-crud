<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Attribute\AsUiTreeAction;

/** `{"kind": "create", "screen": "playground.products"}` — the screen's create dialog. */
#[AsService]
#[AsUiTreeAction(kind: 'create')]
final class CrudCreateTreeAction extends CrudScreenTreeAction
{
    protected function operation(): string
    {
        return 'create';
    }

    public function describe(): string
    {
        return '{"kind": "create", "screen": "<crud screen id>"} — opens that screen\'s create dialog (a button\'s href). Screens: ' . $this->screensForVisitor() . '.';
    }
}
