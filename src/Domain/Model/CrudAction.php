<?php

declare(strict_types=1);

namespace Semitexa\Crud\Domain\Model;

use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridAction;

/**
 * One action of a CRUD screen, on a row, on a selection, or in the header:
 *
 *     CrudAction::delete()                                    // row + bulk, confirmed
 *     CrudAction::of('activate', 'Activate', ActivateProducts::class)->onRows()->inBulk()
 *     CrudAction::of('restock', 'Restock all', Restock::class)->inHeader()->confirm('Restock every product?')
 *
 * Its permission is one of the screen's operations ("edit" → catalog.edit) or
 * a whole permission ("catalog.publish"); the action is hidden from, and
 * refused to, a visitor without it.
 */
final readonly class CrudAction
{
    public const DELETE = 'delete';

    /**
     * @param list<string>         $scopes
     * @param class-string|null    $handler a #[AsCrudAction] class; null for the built-in delete
     */
    private function __construct(
        public string $id,
        public string $label,
        public ?string $handler,
        public array $scopes,
        public string $permission,
        public ?string $confirm,
        public string $tone,
    ) {}

    public static function delete(): self
    {
        return new self(self::DELETE, 'Delete', null, ['row', 'bulk'], 'delete', 'Delete this {label}? This cannot be undone.', 'danger');
    }

    /** @param class-string $handler a class carrying #[AsCrudAction] */
    public static function of(string $id, string $label, string $handler): self
    {
        if ($id === self::DELETE) {
            throw new \InvalidArgumentException('"delete" is the built-in action; use CrudAction::delete().');
        }

        return new self($id, $label, $handler, [], 'edit', null, 'neutral');
    }

    public function onRows(): self { return $this->scope('row'); }
    public function inBulk(): self { return $this->scope('bulk'); }
    public function inHeader(): self { return $this->scope('header'); }

    public function confirm(string $question): self
    {
        return new self($this->id, $this->label, $this->handler, $this->scopes, $this->permission, $question, $this->tone);
    }

    public function tone(string $tone): self
    {
        return new self($this->id, $this->label, $this->handler, $this->scopes, $this->permission, $this->confirm, $tone);
    }

    /** An operation of the screen ("edit") or a whole permission ("catalog.publish"). */
    public function requires(string $permission): self
    {
        return new self($this->id, $this->label, $this->handler, $this->scopes, $permission, $this->confirm, $this->tone);
    }

    /** The permission this action needs on a screen, or null when signing in is enough. */
    public function permissionOn(AsCrud $crud): ?string
    {
        return str_contains($this->permission, '.') ? $this->permission : $crud->permissionFor($this->permission);
    }

    /**
     * What the grid is rendered with (signed into it). `{label}` in a question
     * is the screen's record ("product"), `{plural}` its records ("products"),
     * and `{count}` the size of a selection.
     */
    public function toGridAction(AsCrud $crud): UiGridAction
    {
        $words = ['{label}' => mb_strtolower($crud->label), '{plural}' => mb_strtolower($crud->plural())];
        $confirm = $this->confirm === null ? null : strtr($this->confirm, $words);
        $confirmBulk = $this->id === self::DELETE ? strtr('Delete {count} {plural}? This cannot be undone.', $words) : null;

        // A delete is optimistic: the rows go the moment it is confirmed.
        return new UiGridAction($this->id, $this->label, $this->scopes, $confirm, $this->tone, $confirmBulk, removesRows: $this->id === self::DELETE);
    }

    private function scope(string $scope): self
    {
        $scopes = array_values(array_unique([...$this->scopes, $scope]));

        return new self($this->id, $this->label, $this->handler, $scopes, $this->permission, $this->confirm, $this->tone);
    }
}
