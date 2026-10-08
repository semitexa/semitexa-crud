<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Settings;

use Semitexa\Crud\Application\Payload\Request\SettingsDefinition;
use Semitexa\Crud\Attribute\AsSettingsPage;

/**
 * The application's settings pages by id, discovered at worker boot: a form
 * names its page by id (signed), a page lists the other sections of its
 * group, navigation lists the pages that ask to be. Two pages with one id
 * fail boot — they would share stored values.
 */
final class SettingsPages
{
    /** @var array<string, class-string<SettingsDefinition>> */
    private static array $classes = [];

    /** @param list<class-string> $classes classes carrying #[AsSettingsPage] */
    public static function discover(array $classes): void
    {
        $found = [];
        foreach ($classes as $class) {
            if (!is_subclass_of($class, SettingsDefinition::class)) {
                throw new \LogicException(sprintf('%s carries #[AsSettingsPage] but does not extend %s.', $class, SettingsDefinition::class));
            }
            $id = (new $class())->page()->id;
            if (isset($found[$id])) {
                throw new \LogicException(sprintf('Settings page "%s" is declared twice: %s and %s.', $id, $found[$id], $class));
            }
            $found[$id] = $class;
        }
        self::$classes = $found;
    }

    public static function reset(): void
    {
        self::$classes = [];
    }

    public static function get(string $id): ?SettingsDefinition
    {
        $class = self::$classes[$id] ?? null;

        return $class === null ? null : new $class();
    }

    /** @return list<AsSettingsPage> every page, by group, then order, then title */
    public static function all(): array
    {
        $pages = array_map(static fn (string $class): AsSettingsPage => (new $class())->page(), array_values(self::$classes));
        usort($pages, static fn (AsSettingsPage $a, AsSettingsPage $b): int => [$a->group, $a->order, $a->title] <=> [$b->group, $b->order, $b->title]);

        return $pages;
    }

    /** @return list<AsSettingsPage> the sections of one settings area */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::all(), static fn (AsSettingsPage $page): bool => $page->group === $group));
    }
}
