<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Screen;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * A screen's records in the ORM: found through the screen's own query() (a
 * screen narrowed to some records cannot edit the others), scoped to the
 * request's tenant when the model is tenant-scoped, and written through the
 * aggregate write engine — so the tenant is stamped, #[Version] is checked,
 * the change event refreshes every open grid, and replication sees the write.
 *
 * Worker singleton: the tenant is read from the store on every call.
 */
#[AsService]
final class CrudRecords
{
    /** Set on every write when the model has them and no field writes them. */
    private const CREATED = ['createdAt', 'created_at'];
    private const UPDATED = ['updatedAt', 'updated_at'];

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    public function find(CrudDefinition $screen, string $id): ?object
    {
        $metadata = $this->metadata($screen);

        return $screen->query($this->query($screen))
            ->where(ColumnRef::for($metadata->className, self::primaryKey($metadata)), Operator::Equals, $id)
            ->fetchOne();
    }

    /**
     * The records with these ids that the screen holds, in the order asked
     * (an id the screen's query() does not reach is left out).
     *
     * @param list<string> $ids
     * @return list<object>
     */
    public function findMany(CrudDefinition $screen, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $metadata = $this->metadata($screen);
        $pk = self::primaryKey($metadata);
        $byId = [];
        $query = $screen->query($this->query($screen))->whereIn(ColumnRef::for($metadata->className, $pk), $ids);
        foreach ($query->fetchAll() as $record) {
            $byId[(string) $record->{$pk}] = $record;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?object => $byId[$id] ?? null, $ids)));
    }

    /**
     * The screen's records a header action works on: its query(), narrowed.
     *
     * @param callable(ResourceModelQuery): ResourceModelQuery $narrow
     * @return list<object>
     */
    public function matching(CrudDefinition $screen, callable $narrow): array
    {
        return array_values($narrow($this->scoped($screen))->fetchAll());
    }

    /**
     * A query over exactly the records the screen holds: the request's tenant,
     * then the screen's query(). Count, group or narrow it further.
     */
    public function scoped(CrudDefinition $screen): ResourceModelQuery
    {
        return $screen->query($this->query($screen));
    }

    /**
     * The model's moment column for "when", by convention: updated, else
     * created (camelCase or snake_case); null when it has neither.
     */
    public function momentProperty(CrudDefinition $screen, bool $created = false): ?string
    {
        $metadata = $this->metadata($screen);
        $names = $created ? self::CREATED : [...self::UPDATED, ...self::CREATED];
        foreach ($names as $property) {
            if ($metadata->hasColumn($property)) {
                return $property;
            }
        }

        return null;
    }

    public static function idOf(object $record, ResourceModelMetadata $metadata): string
    {
        return (string) $record->{self::primaryKey($metadata)};
    }

    /** The record's #[Version], signed into the edit form so a stale save is refused. */
    public function versionOf(CrudDefinition $screen, object $record): ?int
    {
        $property = $this->metadata($screen)->versionProperty;

        return $property === null ? null : (int) $record->{$property};
    }

    /**
     * Fields another record already holds the value of, by the model's
     * single-column unique indexes — answered as field errors before the
     * write, instead of a constraint failure after it.
     *
     * @param array<string, mixed> $values field name → cast value
     * @return array<string, string> field name → message
     */
    public function takenValues(CrudDefinition $screen, array $values, ?object $existing): array
    {
        $metadata = $this->metadata($screen);
        $unique = self::uniqueProperties($metadata);
        $errors = [];
        foreach ($screen->fields() as $field) {
            $property = self::property($field, $metadata);
            if (!in_array($property, $unique, true) || ($values[$field->name] ?? null) === null) {
                continue;
            }
            $query = $screen->query($this->query($screen))
                ->where(ColumnRef::for($metadata->className, $property), Operator::Equals, $values[$field->name]);
            if ($existing !== null) {
                $pk = self::primaryKey($metadata);
                $query->where(ColumnRef::for($metadata->className, $pk), Operator::NotEquals, $existing->{$pk});
            }
            if ($query->exists()) {
                $errors[$field->name] = sprintf('Another %s already has this %s.', mb_strtolower($screen->crud()->label), mb_strtolower($field->label));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values field name → cast value, the form's writable fields only
     * @return object the record as stored
     */
    public function save(CrudDefinition $screen, ?object $existing, array $values, ?int $version = null): object
    {
        $metadata = $this->metadata($screen);
        $overrides = [];
        foreach ($screen->fields() as $field) {
            if (array_key_exists($field->name, $values)) {
                $overrides[self::property($field, $metadata)] = $values[$field->name];
            }
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ([...($existing === null ? self::CREATED : []), ...self::UPDATED] as $stamp) {
            if ($metadata->hasColumn($stamp) && !array_key_exists($stamp, $overrides)) {
                $overrides[$stamp] = $now;
            }
        }
        if ($existing !== null && $metadata->versionProperty !== null && $version !== null) {
            $overrides[$metadata->versionProperty] = $version;
        }

        return $this->orm->getAggregateWriteEngine()->write(
            $existing === null ? ResourceChangeOperation::Insert : ResourceChangeOperation::Update,
            self::build($metadata->className, $overrides, $existing),
            $this->tenant($metadata),
        );
    }

    public function delete(CrudDefinition $screen, object $existing): void
    {
        $this->orm->getAggregateWriteEngine()->write(ResourceChangeOperation::Delete, $existing, $this->tenant($this->metadata($screen)));
    }

    public function metadata(CrudDefinition $screen): ResourceModelMetadata
    {
        return $this->orm->getResourceModelMetadataRegistry()->for($screen->crud()->model());
    }

    /**
     * The model property a field writes: its "property" setting, its name, or
     * its snake_case twin — a column, or for a belongsToMany the relation.
     */
    public static function property(UiField $field, ResourceModelMetadata $metadata): string
    {
        $declared = (string) $field->setting('property', $field->name);
        $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $declared));
        if ($field->type === 'belongsToMany') {
            foreach ([$declared, $snake] as $candidate) {
                if ($metadata->hasRelation($candidate)) {
                    return $candidate;
                }
            }
        }

        return match (true) {
            $metadata->hasColumn($declared) => $declared,
            $metadata->hasColumn($snake) => $snake,
            default => throw new \LogicException(sprintf(
                'Field "%s" maps to no column of %s (tried "%s" and "%s"); set its "property".',
                $field->name,
                $metadata->className,
                $declared,
                $snake,
            )),
        };
    }

    private function query(CrudDefinition $screen): ResourceModelQuery
    {
        $metadata = $this->metadata($screen);
        $query = $this->orm->query($metadata->className);
        $tenant = $this->tenant($metadata);

        return $tenant === null ? $query : $query->forTenant($tenant);
    }

    private function tenant(ResourceModelMetadata $metadata): ?string
    {
        return $metadata->tenantPolicy === null
            ? null
            : TenantContextAccess::tenantIdOrDefault($this->tenantContextStore->tryGet());
    }

    private static function primaryKey(ResourceModelMetadata $metadata): string
    {
        return $metadata->primaryKeyProperty
            ?? throw new \LogicException(sprintf('%s has no #[PrimaryKey]; a CRUD screen needs one to address its records.', $metadata->className));
    }

    /** @return list<string> properties carrying a single-column unique index */
    private static function uniqueProperties(ResourceModelMetadata $metadata): array
    {
        $properties = [];
        foreach ((new \ReflectionClass($metadata->className))->getAttributes(Index::class) as $attribute) {
            $index = $attribute->newInstance();
            if (!$index->unique || count($index->columns) !== 1) {
                continue;
            }
            foreach ($metadata->columns() as $column) {
                if ($column->columnName === $index->columns[0] || $column->propertyName === $index->columns[0]) {
                    $properties[] = $column->propertyName;
                }
            }
        }

        return $properties;
    }

    /**
     * The model, through its constructor: the overrides, else the edited
     * record's values, else the parameter's default.
     *
     * @param class-string         $class
     * @param array<string, mixed> $overrides property → value
     */
    private static function build(string $class, array $overrides, ?object $existing): object
    {
        $constructor = (new \ReflectionClass($class))->getConstructor()
            ?? throw new \LogicException(sprintf('%s has no constructor; a CRUD screen builds its records through one.', $class));
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = match (true) {
                array_key_exists($name, $overrides) => self::coerce($parameter, $overrides[$name]),
                $existing !== null => (new \ReflectionProperty($existing, $name))->getValue($existing),
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                $parameter->allowsNull() => null,
                default => throw new \LogicException(sprintf('%s::__construct() needs $%s, which no field of the screen writes and which has no default.', $class, $name)),
            };
        }

        return new $class(...$arguments);
    }

    /** A field's cast value in the parameter's own type (a date string for a DateTimeImmutable, say). */
    private static function coerce(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();
        if ($value === null || !$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            \DateTimeImmutable::class, \DateTimeInterface::class => $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')),
            'float' => (float) $value,
            'int' => (int) $value,
            'string' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value),
            'bool' => (bool) $value,
            default => $value,
        };
    }
}
