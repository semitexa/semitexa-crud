<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Marks the code behind a screen's own action. A screen lists it in actions():
 *
 *     CrudAction::of('activate', 'Activate', ActivateProducts::class)->onRows()->inBulk()
 *
 *     #[AsService]
 *     #[AsCrudAction]
 *     final class ActivateProducts implements CrudActionHandlerInterface { … }
 *
 * The screen runtime has already checked the action's permission and found
 * the records through the screen's query(); the handler only does the work.
 * It is resolved per call, so its injections are the visitor's.
 */
#[Capability(
    id: 'crud.action',
    summary: 'A CRUD screen\'s own action on a row, a selection of rows or the header, with its permission and confirmation; the runtime checks access and finds the records.',
    useWhen: 'Records of a screen need an operation beyond create, edit and delete - publish, archive, recalculate.',
    avoidWhen: 'The operation needs its own form - give it a page or a dialog of its own.',
    replaces: [
        'a POST route, handler and permission check per grid action',
    ],
    seeAlso: 'crud.screen',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCrudAction
{
}
