<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * The canonical collection envelope — `{data, meta: {pagination,
 * filterOptions}}`, the same shape withResources() writes — from plain rows a
 * field-driven feed projected itself (no Resource DTO class per feed).
 */
#[AsResource(handle: 'crud.collection-feed')]
final class CollectionFeedJsonResponse extends JsonResourceResponse
{
    /**
     * @param list<array<string, mixed>>                              $rows
     * @param array<string, list<array{value: string, label: string}>> $filterOptions
     */
    public function withRows(array $rows, ?CollectionPage $page = null, ?CollectionCursorPage $cursorPage = null, array $filterOptions = [], ?string $key = null): self
    {
        $envelope = ['data' => $rows];
        if ($page !== null) {
            $envelope['meta'] = ['pagination' => $page->toArray()];
        } elseif ($cursorPage !== null) {
            $envelope['meta'] = ['pagination' => $cursorPage->toArray()];
        }
        if ($filterOptions !== []) {
            $envelope['meta'] ??= [];
            $envelope['meta']['filterOptions'] = $filterOptions;
        }
        if ($key !== null) {
            // The row id field: a live re-run is sent as a keyed patch by it.
            $envelope['meta'] ??= [];
            $envelope['meta']['key'] = $key;
        }

        $this->setContent(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->setStatusCode(HttpStatus::Ok->value);
        $this->setHeader('Content-Type', 'application/json');

        return $this;
    }
}
