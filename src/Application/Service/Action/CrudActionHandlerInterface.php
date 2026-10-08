<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Action;

use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;

/** The code behind a screen's own action (#[AsCrudAction]). */
interface CrudActionHandlerInterface
{
    /**
     * @param list<object> $records the rows asked for, as the screen's query() finds them
     *                              (none for a header action)
     */
    public function run(CrudDefinition $screen, array $records): UiGridActionResult;
}
