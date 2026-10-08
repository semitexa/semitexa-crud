<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Screen;

use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlers;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\Crud\Domain\Model\CrudAction;

/**
 * The application's CRUD screens by id, discovered from #[AsCrud] at worker
 * boot. A form names its screen by id (signed into the form); this turns the
 * id back into the definition, and lists the screens for navigation and the
 * command palette. Two screens with one id fail boot.
 */
final class CrudScreens
{
    /** @var array<string, class-string<CrudDefinition>> */
    private static array $classes = [];

    /** @param list<class-string> $classes classes carrying #[AsCrud] */
    public static function discover(array $classes): void
    {
        $found = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, CrudDefinition::class)) {
                throw new \LogicException(sprintf('%s carries #[AsCrud] but does not extend %s.', $class, CrudDefinition::class));
            }
            $id = self::attribute($class)->id;
            if (isset($found[$id])) {
                throw new \LogicException(sprintf('CRUD screen "%s" is declared twice: %s and %s.', $id, $found[$id], $class));
            }
            self::assertActions($class);
            $found[$id] = $class;
        }
        ksort($found);
        self::$classes = $found;
    }

    public static function reset(): void
    {
        self::$classes = [];
    }

    public static function get(string $id): ?CrudDefinition
    {
        $class = self::$classes[$id] ?? null;

        return $class === null ? null : new $class();
    }

    /** @return array<string, AsCrud> id → declaration, sorted by id */
    public static function all(): array
    {
        return array_map(self::attribute(...), self::$classes);
    }

    /**
     * Every action has a scope, a unique id, and code behind it — checked at
     * boot, not at the first click.
     *
     * @param class-string<CrudDefinition> $class
     */
    private static function assertActions(string $class): void
    {
        $seen = [];
        foreach ((new $class())->actions() as $action) {
            if (!$action instanceof CrudAction) {
                throw new \LogicException(sprintf('%s::actions() must list CrudAction values.', $class));
            }
            if (isset($seen[$action->id])) {
                throw new \LogicException(sprintf('%s lists action "%s" twice.', $class, $action->id));
            }
            if ($action->scopes === []) {
                throw new \LogicException(sprintf('%s action "%s" has no scope: add ->onRows(), ->inBulk() or ->inHeader().', $class, $action->id));
            }
            if ($action->handler !== null && !CrudActionHandlers::has($action->handler)) {
                throw new \LogicException(sprintf('%s action "%s" runs %s, which is not a #[AsCrudAction] class.', $class, $action->id, $action->handler));
            }
            $seen[$action->id] = true;
        }
    }

    /** @param class-string $class */
    private static function attribute(string $class): AsCrud
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsCrud::class);
        if ($attributes === []) {
            throw new \LogicException(sprintf('%s has no #[AsCrud].', $class));
        }

        return $attributes[0]->newInstance();
    }
}
