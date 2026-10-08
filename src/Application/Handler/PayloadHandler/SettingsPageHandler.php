<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Crud\Application\Payload\Request\SettingsDefinition;
use Semitexa\Crud\Application\Resource\Response\SettingsPageResponse;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Crud\Application\Service\Settings\SettingsValues;
use Semitexa\Crud\Attribute\AsSettingsPage;

/**
 * Every #[AsSettingsPage]: the sections of its group the visitor may open
 * (the template asks can()), and its form with the current values.
 */
#[AsPayloadHandler(payload: SettingsDefinition::class, resource: SettingsPageResponse::class)]
final class SettingsPageHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SettingsValues $values;

    public function handle(SettingsDefinition $payload, SettingsPageResponse $response): SettingsPageResponse
    {
        $page = $payload->page();
        $response->pageTitle($page->title . ' settings');

        return $response->withPage([
            'id' => $page->id,
            'title' => $page->title,
            'description' => $page->description,
            'layout' => $page->layout,
            'fields' => array_values(array_filter($payload->fields(), static fn ($field): bool => $field->isOnForm() && !$field->readOnly)),
            'values' => $this->values->of($payload),
            'sections' => array_map(static fn (AsSettingsPage $section): array => [
                'key' => $section->id,
                'label' => $section->title,
                'href' => (string) $section->path,
                'icon' => $section->icon,
                'permission' => $section->permission,
            ], SettingsPages::inGroup($page->group)),
        ]);
    }
}
