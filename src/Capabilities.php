<?php

declare(strict_types=1);

namespace Semitexa\Crud;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog. Nothing reads this at
 * runtime; the per-feature declarations sit on the attributes (#[AsCrud],
 * #[AsCollectionFeed]).
 */
#[Capability(
    id: 'crud.kit',
    summary: 'Admin screens from declarations: a CRUD screen (list, live grid, create and edit dialogs, delete, permissions, navigation) or a live grid feed over an ORM model, from a list of fields.',
    useWhen: 'An admin lists, searches, filters, creates, edits and deletes records of one ORM model and should see writes live.',
    avoidWhen: 'The screen is not about one model\'s rows (a dashboard, a wizard) - compose platform-ui components directly.',
    replaces: [
        'list, create, edit and delete pages, each a payload, handler and template',
        'a feed payload, handler, JSON response and Resource DTO per grid',
    ],
    seeAlso: 'crud.screen',
    usage: <<<'USAGE'
        // Which one to reach for, each with its own usage (ai:ask mechanisms --id=...):
        // crud.screen          an admin lists and edits the records of one model
        // crud.collection-feed a grid over one model, on a page of your own
        // crud.action          an operation on a screen's rows beyond create, edit and delete
        // crud.settings-page   editable configuration: names, thresholds, switches
        //
        // A dashboard or a wizard is not about one model's rows: use platform-ui components.
        USAGE,
)]
final class Capabilities
{
}
