<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Resource\Response\CrudPageResponse;
use Semitexa\Crud\Application\Service\Relation\RelationOptions;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;

/**
 * Every #[AsCrud] screen's page (its HTML profile; the JSON profile is
 * CollectionFeedHandler). The visitor already holds `{permission}.read` — the
 * route requires it. The page names the permission behind each control and
 * the template leaves out what can() refuses; the form and grid actions check
 * again on every submit.
 *
 * `?edit=<id>` renders the edit dialog for that record, open, and `?view=<id>`
 * (or `?edit` for a visitor who may not edit) a read-only one with every
 * field; an id the screen does not hold (or no longer holds) answers 404
 * with the list.
 */
#[AsPayloadHandler(payload: CrudDefinition::class, resource: CrudPageResponse::class)]
final class CrudPageHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    #[InjectAsReadonly]
    protected RelationOptions $relations;

    public function handle(CrudDefinition $payload, CrudPageResponse $response): CrudPageResponse
    {
        $crud = $payload->crud();
        // Relation fields get their choices (a select, checkboxes, labels in the view).
        $fields = new UiFieldSet($this->relations->resolve($crud->model(), $payload->fields()));

        $editing = null;
        $viewing = null;
        $missing = null;
        // ?edit for a visitor who may not edit shows the record instead —
        // which dialog to render, so it is decided here, not in the template.
        $editedId = UiPermissions::permits($crud->permissionFor('edit')) ? $payload->editedId() : null;
        $viewedId = $editedId === null ? ($payload->viewedId() ?? $payload->editedId()) : null;
        $wanted = $editedId ?? $viewedId;
        $record = $wanted === null ? null : $this->records->find($payload, $wanted);
        if ($wanted !== null && $record === null) {
            $missing = $wanted;
            $response->setStatusCode(HttpStatus::NotFound->value);
        } elseif ($record !== null && $editedId !== null) {
            $editing = [
                'id' => $editedId,
                'title' => $payload->title($record, $editedId),
                'values' => $this->formValues($payload, $fields, $record),
                'version' => $this->records->versionOf($payload, $record),
            ];
        } elseif ($record !== null && $viewedId !== null) {
            $viewing = [
                'id' => $viewedId,
                // the parameter that brought it: the dialog follows that one
                'param' => $payload->viewedId() !== null ? 'view' : 'edit',
                'title' => $payload->title($record, $viewedId),
                'rows' => self::display($fields, $this->withPivotValues($payload, $fields, $record, $payload->row($record, $fields))),
            ];
        }

        $response->pageTitle($crud->plural());

        return $response->withScreen([
            'id' => $crud->id,
            'path' => $crud->path,
            'label' => $crud->label,
            'plural' => $crud->plural(),
            'layout' => $crud->layout,
            'idField' => $fields->idField(),
            'formFields' => array_values(array_filter($fields->onForm(), static fn ($field): bool => !$field->readOnly)),
            // What each control needs; the template asks can() (null: signed in).
            'permissions' => [
                'create' => $crud->permissionFor('create'),
                'edit' => $crud->permissionFor('edit'),
                'delete' => $crud->permissionFor('delete'),
            ],
            'actions' => array_map(
                static fn ($action): array => ['permission' => $action->permissionOn($crud), 'props' => $action->toGridAction($crud)->toProps()],
                $payload->actions(),
            ),
            'editing' => $editing,
            'viewing' => $viewing,
            'missing' => $missing,
        ]);
    }

    /**
     * The edit form's values: the record's own, with a many-to-many relation
     * as the ids of its related records (what its checkboxes hold).
     *
     * @return array<string, mixed>
     */
    private function formValues(CrudDefinition $screen, UiFieldSet $fields, object $record): array
    {
        $values = $screen->formValues($record);
        $metadata = $this->records->metadata($screen);
        $id = CrudRecords::idOf($record, $metadata);
        foreach ($fields->all() as $field) {
            if ($field->type === 'belongsToMany' && array_key_exists($field->name, $values)) {
                $values[$field->name] = $this->relations->pivotIds($this->relations->relationOf($metadata, $field), [$id])[$id] ?? [];
            }
        }

        return $values;
    }

    /**
     * A record's many-to-many fields as their choices' labels, read from the
     * pivot (the model's relation property is not loaded).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withPivotValues(CrudDefinition $screen, UiFieldSet $fields, object $record, array $row): array
    {
        $metadata = $this->records->metadata($screen);
        $id = CrudRecords::idOf($record, $metadata);
        foreach ($fields->all() as $field) {
            if ($field->type === 'belongsToMany') {
                $labels = array_column($field->options, 'label', 'value');
                $related = $this->relations->pivotIds($this->relations->relationOf($metadata, $field), [$id])[$id] ?? [];
                $row[$field->name] = $related === [] ? null : implode(', ', array_map(static fn (string $r): string => $labels[$r] ?? $r, $related));
            }
        }

        return $row;
    }

    /**
     * Every field of a record as label + text, for the read-only dialog: a
     * choice by its option's label, a switch as Yes / No, nothing as "—".
     *
     * @param array<string, mixed> $row the record as CrudDefinition::row() projects it
     * @return list<array{field: string, label: string, value: string, mono: bool}>
     */
    private static function display(UiFieldSet $fields, array $row): array
    {
        $out = [];
        foreach ($fields->all() as $field) {
            $value = $row[$field->name] ?? null;
            $labels = array_column($field->options, 'label', 'value');
            $text = match (true) {
                $value === null || $value === '' => '—',
                $field->type === 'boolean' => $value ? 'Yes' : 'No',
                $field->type === 'datetime' => $value . ' UTC',
                is_scalar($value) && isset($labels[(string) $value]) => (string) $labels[(string) $value],
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
            $out[] = ['field' => $field->name, 'label' => $field->label, 'value' => $text, 'mono' => $field->type === 'id'];
        }

        return $out;
    }
}
