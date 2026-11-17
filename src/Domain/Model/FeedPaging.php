<?php

declare(strict_types=1);

namespace Semitexa\Crud\Domain\Model;

use Semitexa\Core\Resource\CollectionPaginationPolicy;

/**
 * How a feed pages its rows: the mode, the page sizes a visitor may pick, the
 * cap, and the row count above which `auto` stops counting. A feed attribute
 * takes it whole (`paging: new FeedPaging(defaultPerPage: 50)`), so the
 * attribute that says who may read the rows is not also a row of paging knobs.
 */
final readonly class FeedPaging
{
    /** @param list<int> $perPageOptions */
    public function __construct(
        public string $mode = CollectionPaginationPolicy::MODE_PAGE,
        public int $defaultPerPage = 25,
        public array $perPageOptions = [10, 25, 50, 100],
        public int $maxPerPage = 100,
        public int $countThreshold = 1000,
    ) {
    }

    public function policy(): CollectionPaginationPolicy
    {
        return new CollectionPaginationPolicy(
            mode: $this->mode,
            defaultPerPage: $this->defaultPerPage,
            perPageOptions: $this->perPageOptions,
            maxPerPage: $this->maxPerPage,
            countThreshold: $this->countThreshold,
        );
    }
}
