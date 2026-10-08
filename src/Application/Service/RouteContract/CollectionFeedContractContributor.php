<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\RouteContract;

use Semitexa\Api\Application\Service\Collection\CollectionContractBlock;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteContractBlockContributorInterface;
use Semitexa\Core\Http\WatchScopesOf;
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Application\Service\Feed\FeedDeclarations;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;

/**
 * The OPTIONS contract of a field-driven feed: the `collection` block (the
 * same builder as an attribute-declared route) and the `ui` block — columns,
 * filters and the row id field — from the feed's fields, so the grid renders
 * what was declared instead of guessing from field names.
 */
#[AsService]
#[SatisfiesServiceContract(of: RouteContractBlockContributorInterface::class)]
final class CollectionFeedContractContributor implements RouteContractBlockContributorInterface
{
    public function contributeBlocks(string $payloadClass, ?string $responseClass): array
    {
        if (!class_exists($payloadClass) || !is_subclass_of($payloadClass, CollectionFeed::class)) {
            return [];
        }
        /** @var CollectionFeed $feed */
        $feed = new $payloadClass();
        $fields = new UiFieldSet($feed->fields());

        $blocks = ['ui' => $fields->contractUi() + ['idField' => $fields->idField()]];
        $collection = CollectionContractBlock::build(FeedDeclarations::of($fields, $feed->declaration()), WatchScopesOf::payload($payloadClass));
        if ($collection !== null) {
            $blocks['collection'] = $collection;
        }

        return $blocks;
    }

    public function resolveResourceClass(?string $responseClass): ?string
    {
        return null;
    }
}
