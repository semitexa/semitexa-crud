<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

/** A settings page: its section nav and its one form. */
#[AsResource(handle: 'crud.settings-page', template: '@project-layouts-crud/settings/page.html.twig')]
final class SettingsPageResponse extends HtmlResponse implements ResourceInterface
{
    /** @param array<string, mixed> $page what settings/page.html.twig reads as `page` */
    public function withPage(array $page): self
    {
        return $this->with('page', $page);
    }
}
