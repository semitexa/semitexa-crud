<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Payload\Request;

use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\Crud\Domain\Model\CrudAction;

/**
 * The base of a CRUD screen (#[AsCrud]). A screen is a field-driven feed —
 * its JSON profile is the grid's collection — that also renders its own page
 * and writes its records, so a definition says only what is particular to it:
 *
 *   - fields()   the columns and the form, from one list;
 *   - query()    optionally, which records the screen manages;
 *   - title()    optionally, how one record is named in headings;
 *   - check()    optionally, rules that need the database or several fields;
 *   - actions()  optionally, what can be done to rows, a selection, or all.
 *
 * The page reads `?create`, `?edit=<id>` and `?view=<id>` to open its dialog, so a dialog
 * is a link: bookmarkable, and back closes it.
 */
abstract class CrudDefinition extends CollectionFeed
{
    private ?string $edit = null;
    private ?string $view = null;

    final public function crud(): AsCrud
    {
        $route = $this->declaration();
        if (!$route instanceof AsCrud) {
            throw new \LogicException(sprintf('%s extends CrudDefinition but has no #[AsCrud].', static::class));
        }

        return $route;
    }

    /** How a record is named in a heading: its first text field, else its id. */
    public function title(object $model, string $id): string
    {
        foreach ($this->fields() as $field) {
            if (in_array($field->type, ['text', 'email', 'slug'], true)) {
                $value = self::read($model, (string) $field->setting('property', $field->name));
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $id;
    }

    /**
     * The grid's server actions. Default: delete, on a row and on a selection.
     * Add your own with CrudAction::of(…) and a #[AsCrudAction] class; drop
     * delete by leaving it out.
     *
     * @return list<CrudAction>
     */
    public function actions(): array
    {
        return [CrudAction::delete()];
    }

    /**
     * A record's values as its edit form shows them: each writable field's
     * stored value, unprojected (a moment stays a DateTimeImmutable).
     *
     * @return array<string, mixed> field name → value
     */
    public function formValues(object $model): array
    {
        $values = [];
        foreach ($this->fields() as $field) {
            if ($field->isOnForm() && !$field->readOnly) {
                $values[$field->name] = self::read($model, (string) $field->setting('property', $field->name));
            }
        }

        return $values;
    }

    /**
     * Checks the field rules cannot make: across fields, or against stored
     * records. The values are already cast and have passed every field's rules.
     *
     * @param array<string, mixed> $values field name → value
     * @param object|null          $existing the record being edited; null on create
     * @return array<string, string> field name → message
     */
    public function check(array $values, ?object $existing): array
    {
        return [];
    }

    // ---- the dialog in the address ------------------------------------------

    public function setEdit(string $v): void { $this->edit = trim($v) === '' ? null : trim($v); }
    public function editedId(): ?string { return $this->edit; }
    public function setView(string $v): void { $this->view = trim($v) === '' ? null : trim($v); }
    public function viewedId(): ?string { return $this->view; }
}
