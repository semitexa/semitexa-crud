<?php

declare(strict_types=1);

namespace Semitexa\Crud\Domain\Model;

use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * One page of a feed as a source answers it: the rows, and either the page
 * (numbered paging) or the cursor page.
 */
final readonly class CollectionSlice
{
    /** @param list<array<string, mixed>> $rows keyed by field name, JSON-plain */
    public function __construct(
        public array $rows,
        public ?CollectionPage $page = null,
        public ?CollectionCursorPage $cursorPage = null,
    ) {
        if (($page === null) === ($cursorPage === null)) {
            throw new \InvalidArgumentException('A collection slice is paged by number or by cursor: give exactly one.');
        }
    }
}
