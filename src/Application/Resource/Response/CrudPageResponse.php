<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

/** A CRUD screen's page: the list, and its create and edit dialogs. */
#[AsResource(handle: 'crud.page', template: '@project-layouts-crud/crud/page.html.twig')]
final class CrudPageResponse extends HtmlResponse implements ResourceInterface
{
    /** @param array<string, mixed> $screen what crud/page.html.twig reads as `screen` */
    public function withScreen(array $screen): self
    {
        return $this->with('screen', $screen);
    }
}
