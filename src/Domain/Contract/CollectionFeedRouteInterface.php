<?php

declare(strict_types=1);

namespace Semitexa\Crud\Domain\Contract;

use Semitexa\Core\Resource\CollectionPaginationPolicy;

/**
 * A route attribute that serves a field-driven collection: #[AsCollectionFeed]
 * (a feed on its own) and #[AsCrud] (a screen whose JSON profile is the feed).
 * The generic feed handler, its contract and its declarations read the model
 * and the paging through this, whichever attribute the class carries.
 */
interface CollectionFeedRouteInterface
{
    /** @return class-string|null the ORM resource model the rows are; null when a source serves them */
    public function model(): ?string;

    /** @return class-string<CollectionSourceInterface>|null the service that serves the rows instead of the ORM */
    public function source(): ?string;

    public function policy(): CollectionPaginationPolicy;
}
