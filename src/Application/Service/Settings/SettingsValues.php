<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Settings;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\SettingsDefinition;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * A settings page's values, for the current tenant: what was saved, else
 * each field's default. Stored in semitexa/platform-settings under the
 * page's id, one setting per field.
 *
 *     $threshold = $this->settings->of(StoreSettings::class)['lowStockThreshold'];
 */
#[AsService]
final class SettingsValues
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $store;

    /**
     * @param class-string<SettingsDefinition>|SettingsDefinition $definition
     * @return array<string, mixed> field name → value
     */
    public function of(string|SettingsDefinition $definition): array
    {
        $definition = is_string($definition) ? new $definition() : $definition;
        $stored = $this->store->getAll($definition->page()->id);
        $values = [];
        foreach ($definition->fields() as $field) {
            $values[$field->name] = array_key_exists($field->name, $stored) ? $stored[$field->name] : $field->default;
        }

        return $values;
    }

    /**
     * Store cast values (UiFieldSet::cast output). A moment is stored as UTC
     * "Y-m-d H:i:s", so what is stored is JSON-plain.
     *
     * @param array<string, mixed> $values
     */
    public function save(SettingsDefinition $definition, array $values): void
    {
        $known = array_flip(array_map(static fn ($field): string => $field->name, $definition->fields()));
        foreach ($values as $name => $value) {
            if (!isset($known[$name])) {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $this->store->set($definition->page()->id, $name, $value);
        }
    }
}
