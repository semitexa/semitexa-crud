<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Action;

use Semitexa\Crud\Attribute\AsCrudAction;

/**
 * The #[AsCrudAction] classes, discovered at worker boot and each resolved
 * per call from the request scope. A screen naming a handler that is not one
 * fails the boot (CrudScreens::discover).
 */
final class CrudActionHandlers
{
    /** @var array<class-string, \Closure(): CrudActionHandlerInterface> */
    private static array $handlers = [];

    /**
     * @param iterable<class-string>         $classes
     * @param callable(class-string): object $resolve
     */
    public static function discover(iterable $classes, callable $resolve): void
    {
        $handlers = [];
        foreach ($classes as $class) {
            if ((new \ReflectionClass($class))->getAttributes(AsCrudAction::class) === []) {
                continue;
            }
            if (!is_subclass_of($class, CrudActionHandlerInterface::class)) {
                throw new \LogicException(sprintf('#[AsCrudAction] class %s must implement %s.', $class, CrudActionHandlerInterface::class));
            }
            $handlers[$class] = static function () use ($resolve, $class): CrudActionHandlerInterface {
                $handler = $resolve($class);
                if (!$handler instanceof CrudActionHandlerInterface) {
                    throw new \LogicException(sprintf('%s did not resolve to a CRUD action handler.', $class));
                }

                return $handler;
            };
        }
        self::$handlers = $handlers;
    }

    /** Test seam. */
    public static function add(CrudActionHandlerInterface $handler): void
    {
        self::$handlers[$handler::class] = static fn (): CrudActionHandlerInterface => $handler;
    }

    public static function has(string $class): bool
    {
        return isset(self::$handlers[$class]);
    }

    public static function get(string $class): ?CrudActionHandlerInterface
    {
        $factory = self::$handlers[$class] ?? null;

        return $factory === null ? null : $factory();
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}
