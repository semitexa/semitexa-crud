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
    usage: <<<'USAGE'
        // On the screen. Overriding actions() replaces the default: list delete to keep it.
        public function actions(): array
        {
            return [
                CrudAction::of('activate', 'Activate', ActivateProducts::class)->onRows()->inBulk(),
                CrudAction::delete(),
            ];
        }

        // The work. The permission is checked and the records are found before run():
        // an id the screen's query() does not hold never reaches it.
        #[AsService]
        #[AsCrudAction]
        final class ActivateProducts implements CrudActionHandlerInterface
        {
            #[InjectAsReadonly]
            protected CrudRecords $records;

            public function run(CrudDefinition $screen, array $records): UiGridActionResult
            {
                foreach ($records as $record) {
                    $this->records->save($screen, $record, ['status' => 'active']);
                }

                return UiGridActionResult::done(sprintf('Activated %d products.', count($records)), count($records));
            }
        }

        // An action needs the screen's edit permission unless ->requires('delete') names another.
        // inHeader() puts it above the grid; confirm('...') asks first. Refuse with UiGridActionResult::refused('...').
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCrudAction
{
}
