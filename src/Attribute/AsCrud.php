<?php

declare(strict_types=1);

namespace Semitexa\Crud\Attribute;

use Attribute;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Contract\DeclaresRequiredPermissionsInterface;
use Semitexa\Core\Contract\DeclaresWatchScopesInterface;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Crud\Application\Resource\Response\CollectionFeedJsonResponse;
use Semitexa\Crud\Application\Resource\Response\CrudPageResponse;
use Semitexa\Crud\Domain\Contract\CollectionFeedRouteInterface;
use Semitexa\Orm\Domain\Model\ResourceMetadata;

/**
 * A CRUD screen in one class: the list page, its live grid, create and edit
 * in a dialog, delete, and a navigation entry — over one ORM model, from the
 * class's fields().
 *
 *     #[AsCrud(id: 'playground.products', path: '/playground/products',
 *              model: PlaygroundProductResource::class, label: 'Product', permission: 'catalog',
 *              nav: 'Catalog', layout: '@project-layouts-Playground/layouts/app.html.twig')]
 *     final class ProductCrud extends CrudDefinition
 *     {
 *         public function fields(): array { return [Field::id(), Field::text('name')->required()->searchable(), …]; }
 *     }
 *
 * One route: HTML is the page, `Accept: application/json` the grid's
 * collection, OPTIONS its contract, and the route's name is what the grid
 * subscribes to through HUG. Writes go through the crud.save / crud.delete
 * form actions, with the screen and the record signed into the form.
 *
 * `permission: 'catalog'` means catalog.read for the page and the feed, and
 * catalog.create / catalog.edit / catalog.delete for the writes; null means
 * any signed-in visitor may do all four. `permissions: ['delete' => 'content.publish']`
 * names another permission for one operation, for an application whose
 * vocabulary is not create / edit / delete.
 */
#[Capability(
    id: 'crud.screen',
    summary: 'A list / create / edit / delete screen over one ORM model, declared as one class with a field list: page, live grid, dialog forms, permissions and navigation.',
    useWhen: 'An administrator manages the records of one model: list, search, filter, create, edit, delete.',
    avoidWhen: 'The screen is a workflow, not records (a wizard, a dashboard) - compose platform-ui components on your own page; or the rows are not one model - use #[AsCollectionFeed] with query().',
    replaces: [
        'a list page, a create page, an edit page and a delete route, each a payload, handler and template',
        'a feed payload and handler for the grid',
        'create and update form actions that copy the form into the model',
    ],
    seeAlso: 'crud.collection-feed',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCrud extends AbstractPayloadRoute implements CollectionFeedRouteInterface, DeclaresWatchScopesInterface, DeclaresRequiredPermissionsInterface
{
    /** The writes a screen performs, each its own permission. */
    public const OPERATIONS = ['read', 'create', 'edit', 'delete'];

    /**
     * @param string       $id         the screen's id and route name ("playground.products")
     * @param class-string $model      the ORM resource model the records are
     * @param string       $label      one record, in the UI's words ("Product")
     * @param ?string      $plural     the records ("Products"); default: the regular plural of label
     * @param ?string      $permission the permission prefix ("catalog" → catalog.read, …); null = signed in
     * @param ?string      $nav        the navigation group to list the screen under; null = not listed
     * @param string       $layout     the Twig layout the page extends; it must define a `main` block
     * @param list<int>    $perPageOptions
     */
    public function __construct(
        public readonly string $id,
        string $path,
        public readonly string $model,
        public readonly string $label,
        public readonly ?string $plural = null,
        public readonly ?string $permission = null,
        public readonly ?string $nav = null,
        public readonly string $icon = 'table',
        public readonly string $layout = '@platform-ui/layouts/app-shell.html.twig',
        public readonly string $paginationMode = CollectionPaginationPolicy::MODE_PAGE,
        public readonly int $defaultPerPage = 25,
        public readonly array $perPageOptions = [10, 25, 50, 100],
        public readonly int $maxPerPage = 100,
        public readonly int $countThreshold = 1000,
        ?string $doc = null,
        /** @var array<string, string> operation → the permission it needs instead of "{permission}.{operation}" */
        public readonly array $permissions = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+\z/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('#[AsCrud] id "%s" must be dotted lowercase words, like "admin.products".', $id));
        }
        foreach (array_keys($permissions) as $operation) {
            if (!in_array($operation, self::OPERATIONS, true)) {
                throw new \InvalidArgumentException(sprintf('#[AsCrud] permissions names "%s"; the operations are %s.', $operation, implode(', ', self::OPERATIONS)));
            }
        }
        if ($permission !== null && preg_match('/\A[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*\z/', $permission) !== 1) {
            throw new \InvalidArgumentException(sprintf('#[AsCrud] permission "%s" must be a permission prefix, like "catalog".', $permission));
        }

        parent::__construct(
            doc: $doc,
            path: $path,
            methods: ['GET'],
            name: $id,
            responseWith: CrudPageResponse::class,
            transport: TransportType::Sse,
            // HTML first: a browser's */* gets the page, the grid's
            // `Accept: application/json` the collection.
            renderProfile: [RenderProfile::Html, RenderProfile::Json],
            responsesByProfile: [
                RenderProfile::Html->value => CrudPageResponse::class,
                RenderProfile::Json->value => CollectionFeedJsonResponse::class,
            ],
            // Protected: every subscribe, view change and live re-run is
            // re-authorized against the session's subject.
            sseGateModel: SseGateModel::Subject,
        );
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Protected;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** A CRUD screen's records are always its ORM model. */
    public function source(): ?string
    {
        return null;
    }

    public function plural(): string
    {
        return $this->plural ?? self::pluralOf($this->label);
    }

    /**
     * The regular English plural of the last word: category → categories,
     * box → boxes, product → products. An irregular one (person → people)
     * is given as `plural:`.
     */
    public static function pluralOf(string $label): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/i', $label) === 1 => substr($label, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/i', $label) === 1 => $label . 'es',
            default => $label . 's',
        };
    }

    /** The permission an operation needs ("catalog.edit"), or null when signing in is enough. */
    public function permissionFor(string $operation): ?string
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown CRUD operation "%s"; expected one of %s.', $operation, implode(', ', self::OPERATIONS)));
        }

        return $this->permissions[$operation] ?? ($this->permission === null ? null : $this->permission . '.' . $operation);
    }

    public function requiredPermissions(string $payloadClass): array
    {
        $read = $this->permissionFor('read');

        return $read === null ? [] : [$read];
    }

    public function policy(): CollectionPaginationPolicy
    {
        return new CollectionPaginationPolicy(
            mode: $this->paginationMode,
            defaultPerPage: $this->defaultPerPage,
            perPageOptions: $this->perPageOptions,
            maxPerPage: $this->maxPerPage,
            countThreshold: $this->countThreshold,
        );
    }

    /** The model's resource key: every ORM write to it refreshes the grid. */
    public function watchScopes(string $payloadClass): array
    {
        return [ResourceMetadata::for($this->model)->getResourceKey()];
    }
}
