<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Crud\Domain\Model\FeedPaging;

/**
 * A collection feed anyone may read, guests included, and it says why: rows
 * anyone may read are a decision, so the reason is required and sits next to
 * the name.
 *
 *     #[AsPublicFeed(path: '/prices/feed', name: 'prices.feed', reason: 'the public price list',
 *         model: PriceResource::class)]
 *     final class PricesFeed extends CollectionFeed { … }
 *
 * Its live updates reach guests on the page's shared stream (the stream's own
 * admit chain gates it: SseGateModel::BearerSession).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsPublicFeed extends AbstractCollectionFeedRoute
{
    /**
     * @param string            $reason why anyone may read these rows, in a sentence
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string<\Semitexa\Crud\Domain\Contract\CollectionSourceInterface>|null $source a service serving them instead (a repository, an API)
     * @param list<string>      $watch  the scopes a source-served feed refreshes on (a model-served one watches its model)
     * @param string|null       $defaultSort the order a request without a sort gets, as `?sort=` writes it; the field must be sortable
     */
    public function __construct(
        string $path,
        string $name,
        string $reason,
        ?string $model = null,
        ?string $source = null,
        FeedPaging $paging = new FeedPaging(),
        array $watch = [],
        ?string $defaultSort = null,
        ?string $doc = null,
    ) {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(sprintf("#[AsPublicFeed] \"%s\": say why anyone may read its rows, reason: 'the public price list'.", $name));
        }
        parent::__construct(
            path: $path,
            name: $name,
            model: $model,
            source: $source,
            paging: $paging,
            watch: $watch,
            defaultSort: $defaultSort,
            doc: $doc,
            sseGateModel: SseGateModel::BearerSession,
            reason: $reason,
        );
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Public;
    }
}
