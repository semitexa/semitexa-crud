<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Contract\DeclaresWatchScopesInterface;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Domain\Contract\CollectionFeedRouteInterface;
use Semitexa\Crud\Domain\Model\FeedPaging;
use Semitexa\Orm\Domain\Model\ResourceMetadata;

/**
 * What every collection feed attribute shares: a GET route answering JSON and
 * streaming live over SSE, its rows named by a model or a source, its paging,
 * its default sort and the scopes it refreshes on. Who may read the rows is
 * the subclass, and its name says it: #[AsProtectedFeed] or #[AsPublicFeed]
 * (ep-feeds-declare-their-access; #[AsCollectionFeed] still says it in an
 * argument). Match feeds by this class, with ReflectionAttribute::IS_INSTANCEOF.
 */
abstract class AbstractCollectionFeedRoute extends AbstractPayloadRoute implements CollectionFeedRouteInterface, DeclaresWatchScopesInterface
{
    /**
     * @param class-string|null $model  the ORM resource model the rows are
     * @param class-string<\Semitexa\Crud\Domain\Contract\CollectionSourceInterface>|null $source a service serving them instead (a repository, an API)
     * @param list<string>      $watch  the scopes a source-served feed refreshes on (a model-served one watches its model)
     * @param string|null       $defaultSort the order a request without a sort gets, as `?sort=` writes it; the field must be sortable
     */
    protected function __construct(
        string $path,
        string $name,
        public readonly ?string $model,
        public readonly ?string $source,
        public readonly FeedPaging $paging,
        public readonly array $watch,
        public readonly ?string $defaultSort,
        ?string $doc,
        SseGateModel $sseGateModel,
        ?string $reason,
    ) {
        if (($model === null) === ($source === null)) {
            throw new \InvalidArgumentException(sprintf('#[%s] "%s" names its rows by a model or by a source: give exactly one.', static::attributeName(), $name));
        }
        parent::__construct(
            doc: $doc,
            path: $path,
            methods: ['GET'],
            name: $name,
            responseWith: CollectionFeedJsonResponse::class,
            renderProfile: RenderProfile::Json,
            transport: TransportType::Sse,
            sseGateModel: $sseGateModel,
            reason: $reason,
        );
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function defaultSort(): ?string
    {
        return $this->defaultSort;
    }

    public function policy(): CollectionPaginationPolicy
    {
        return $this->paging->policy();
    }

    /**
     * The model's resource key (its #[ResourceKey], else its table): the
     * channel every ORM write to it publishes on, so the feed refreshes live
     * with no publish code.
     */
    public function watchScopes(string $payloadClass): array
    {
        return $this->model === null ? $this->watch : [ResourceMetadata::for($this->model)->getResourceKey()];
    }

    /** The attribute's short name, for messages that point at the declaration. */
    protected static function attributeName(): string
    {
        $short = strrchr(static::class, '\\');

        return $short === false ? static::class : substr($short, 1);
    }
}
