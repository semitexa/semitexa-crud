<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Attribute\AsUiTreeAction;

/** `{"kind": "edit", "screen": "playground.products", "record": "<id>"}` — one record's edit dialog. */
#[AsService]
#[AsUiTreeAction(kind: 'edit')]
final class CrudEditTreeAction extends CrudScreenTreeAction
{
    protected function operation(): string
    {
        return 'edit';
    }

    public function describe(): string
    {
        return '{"kind": "edit", "screen": "<crud screen id>", "record": "<id>"} — opens one record\'s edit dialog (a button\'s href). Screens: ' . $this->screensForVisitor() . '.';
    }
}
