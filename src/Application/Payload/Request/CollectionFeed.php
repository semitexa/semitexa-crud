<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Payload\Request;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\LiveFilterParam;
use Semitexa\Core\Request;
use Semitexa\Crud\Domain\Contract\CollectionFeedRouteInterface;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;
use Semitexa\Ssr\Domain\Contract\SseCollectionFeedPayloadInterface;

/**
 * The base of a field-driven feed (#[AsCollectionFeed]). It carries the grid's
 * view parameters — q, page, perPage, sort, filter, cursor; live-overridable,
 * the same six every feed used to declare with a getter, a setter and a raw
 * accessor each — so a feed class only says what is particular to it:
 *
 *   - fields()   the columns, and which are searchable / sortable / filterable;
 *   - query()    optionally, a narrower query (only published rows, say);
 *   - row()      optionally, a different projection of one model.
 *
 * The generic CollectionFeedHandler serves every subclass.
 */
abstract class CollectionFeed implements SseCollectionFeedPayloadInterface
{
    #[LiveFilterParam]
    protected ?string $q = null;
    #[LiveFilterParam]
    protected ?string $page = null;
    #[LiveFilterParam]
    protected ?string $perPage = null;
    #[LiveFilterParam]
    protected ?string $sort = null;
    #[LiveFilterParam]
    protected ?string $filter = null;
    #[LiveFilterParam]
    protected ?string $cursor = null;

    private ?string $streamId = null;
    private ?Request $httpRequest = null;

    /**
     * The feed's fields, in column order: Field::text('title')->searchable(), …
     * A field's name is the row key and, unless it says otherwise, the model
     * property it reads (camelCase or its snake_case twin).
     *
     * @return list<UiField>
     */
    abstract public function fields(): array;

    /** Narrow the base query (it already applies the request's search, filter and sort). */
    public function query(ResourceModelQuery $query): ResourceModelQuery
    {
        return $query;
    }

    /**
     * One model as one row. The default reads each field's property and makes
     * it JSON-plain: a moment becomes "Y-m-d H:i:s" (UTC), a date field's day
     * "Y-m-d" (a calendar day has no zone), an enum its value, a relation the
     * labels of its choices.
     *
     * @return array<string, mixed>
     */
    public function row(object $model, UiFieldSet $fields): array
    {
        $row = [];
        foreach ($fields->all() as $field) {
            $value = self::read($model, (string) $field->setting('property', $field->name));
            $row[$field->name] = $field->type === 'belongsTo' || $field->type === 'belongsToMany'
                ? self::relationLabel($value, $field)
                : self::plain($value, $field->type === 'date');
        }

        return $row;
    }

    /**
     * The class's route attribute — #[AsCollectionFeed], or #[AsCrud] for a
     * screen — which names the model and the paging.
     */
    final public function declaration(): CollectionFeedRouteInterface
    {
        foreach ((new \ReflectionClass($this))->getAttributes(AbstractPayloadRoute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $route = $attribute->newInstance();
            if ($route instanceof CollectionFeedRouteInterface) {
                return $route;
            }
        }

        throw new \LogicException(sprintf('%s extends CollectionFeed but has no #[AsCollectionFeed] (or #[AsCrud]).', static::class));
    }

    // ---- the grid's view parameters --------------------------------------

    public function setHttpRequest(Request $request): void { $this->httpRequest = $request; }
    public function getHttpRequest(): ?Request { return $this->httpRequest; }
    public function setStreamId(?string $streamId): void { $this->streamId = self::trimToNull($streamId); }
    public function getStreamId(): ?string { return $this->streamId; }

    public function getQ(): string { return $this->q ?? ''; }
    public function setQ(string $v): void { $this->q = self::trimToNull($v); }
    public function getPage(): string { return $this->page ?? ''; }
    public function setPage(string $v): void { $this->page = self::trimToNull($v); }
    public function getPerPage(): string { return $this->perPage ?? ''; }
    public function setPerPage(string $v): void { $this->perPage = self::trimToNull($v); }
    public function getSort(): string { return $this->sort ?? ''; }
    public function setSort(string $v): void { $this->sort = self::trimToNull($v); }
    public function getFilter(): string { return $this->filter ?? ''; }
    public function setFilter(string $v): void { $this->filter = self::trimToNull($v); }
    public function getCursor(): string { return $this->cursor ?? ''; }
    public function setCursor(string $v): void { $this->cursor = self::trimToNull($v); }

    public function toViewParams(): array
    {
        return [
            'q'        => $this->q ?? '',
            'page'     => $this->page ?? '',
            'perPage'  => $this->perPage ?? '',
            'sort'     => $this->sort ?? '',
            'filter'   => $this->filter ?? '',
            'cursor'   => $this->cursor ?? '',
            'streamId' => $this->streamId ?? '',
        ];
    }

    protected static function read(object $model, string $property): mixed
    {
        foreach ([$property, strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $property))] as $candidate) {
            if (property_exists($model, $candidate)) {
                return $model->{$candidate};
            }
        }

        throw new \LogicException(sprintf('%s has no property "%s" (nor its snake_case twin); set the field\'s "property" setting.', $model::class, $property));
    }

    private static function plain(mixed $value, bool $day = false): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface && $day => $value->format('Y-m-d'),
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            is_scalar($value), $value === null => $value,
            default => throw new \LogicException(sprintf('A %s cannot be a grid cell; override row() to project it.', get_debug_type($value))),
        };
    }

    /**
     * A relation as people read it: its choices' labels ("News, PHP"), or
     * the id when the choices are not known. A loaded related model is read
     * by its `id`; a relation that was not loaded reads as nothing.
     */
    private static function relationLabel(mixed $value, UiField $field): ?string
    {
        $items = match (true) {
            $value === null => [],
            is_scalar($value) => [$value],
            is_iterable($value) => $value,
            default => [],
        };
        $labels = array_column($field->options, 'label', 'value');
        $out = [];
        foreach ($items as $item) {
            $id = is_object($item) ? (string) ($item->id ?? '') : (string) $item;
            if ($id !== '') {
                $out[] = $labels[$id] ?? $id;
            }
        }

        return $out === [] ? null : implode(', ', $out);
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
