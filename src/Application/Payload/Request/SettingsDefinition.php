<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Payload\Request;

use Semitexa\Crud\Attribute\AsSettingsPage;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * The base of a settings page (#[AsSettingsPage]). It says only what is
 * particular to it: its fields — each with its default, which is the value
 * until someone saves another — and, optionally, check() for rules across
 * fields.
 */
abstract class SettingsDefinition
{
    /** @return list<UiField> */
    abstract public function fields(): array;

    /**
     * Checks the field rules cannot make. The values are cast and have
     * passed every field's rules.
     *
     * @param array<string, mixed> $values
     * @return array<string, string> field name → message
     */
    public function check(array $values): array
    {
        return [];
    }

    final public function page(): AsSettingsPage
    {
        $attributes = (new \ReflectionClass($this))->getAttributes(AsSettingsPage::class);
        if ($attributes === []) {
            throw new \LogicException(sprintf('%s extends SettingsDefinition but has no #[AsSettingsPage].', static::class));
        }

        return $attributes[0]->newInstance();
    }
}
