<?php

declare(strict_types=1);

namespace Semitexa\Crud\Domain\Contract;

use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Domain\Model\CollectionSlice;

/**
 * Where a field-driven feed's rows come from when they are not one ORM model:
 * a repository, an external API, an in-memory store. Named as
 * `#[AsCollectionFeed(source: …)]` and taken from the container per request.
 *
 * The criteria are already checked against what the feed's fields allow
 * (search, sort, filters and their operators, paging), so a source only
 * answers them: the rows, JSON-plain and keyed by field name, and the page.
 */
interface CollectionSourceInterface
{
    public function slice(CollectionCriteria $criteria, CollectionFeed $feed): CollectionSlice;
}
