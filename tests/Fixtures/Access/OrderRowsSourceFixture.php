<?php

declare(strict_types=1);

namespace Semitexa\Crud\Tests\Fixtures\Access;

use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Domain\Contract\CollectionSourceInterface;
use Semitexa\Crud\Domain\Model\CollectionSlice;

/** A source a feed may name instead of a model. Never asked for rows: read by FeedAccessIsInTheAttributeNameTest. */
final class OrderRowsSourceFixture implements CollectionSourceInterface
{
    public function slice(CollectionCriteria $criteria, CollectionFeed $feed): CollectionSlice
    {
        throw new \LogicException('The fixture source is named by a declaration, never read.');
    }
}
