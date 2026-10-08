<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Feed;

use Semitexa\Api\Attribute\CollectionSearchable;
use Semitexa\Api\Domain\Model\Collection\CollectionDeclarations;
use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Filter\FilterOperator;
use Semitexa\Core\Resource\Filter\FilterTerm;
use Semitexa\Crud\Domain\Contract\CollectionFeedRouteInterface;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;

/**
 * A field-driven feed's collection declarations: sortable / searchable /
 * filterable fields and their operators, and the filters whose options the
 * feed serves — read off the fields, so the criteria parser and the OPTIONS
 * contract agree by construction.
 */
final class FeedDeclarations
{
    public static function of(UiFieldSet $fields, CollectionFeedRouteInterface $feed): CollectionDeclarations
    {
        $filter = [];
        $options = [];
        foreach ($fields->all() as $field) {
            if (!$field->filterable) {
                continue;
            }
            $spec = UiFieldTypes::for($field)->filter($field);
            if ($spec === null) {
                throw new \LogicException(sprintf('Field "%s" (%s) is marked filterable, but its type cannot filter.', $field->name, $field->type));
            }
            $filter[$field->name] = $spec['operators'];
            if (isset($spec['options']) && $spec['options'] !== []) {
                $options[] = $field->name;
            }
        }
        $searchable = $fields->searchable();

        return new CollectionDeclarations(
            policy: $feed->policy(),
            searchable: $searchable === [] ? null : new CollectionSearchable(fields: $searchable),
            sort: $fields->sortable(),
            filter: $filter,
            filterOptions: $options,
        );
    }

    /**
     * The options a select filter offers — "(any)" first, as every grid filter
     * already reads them — with a count after each choice where one is given.
     *
     * @param array<string, array<string, int>> $counts field → choice value → records
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function filterOptions(UiFieldSet $fields, array $counts = []): array
    {
        $all = [];
        foreach ($fields->all() as $field) {
            if (!$field->filterable) {
                continue;
            }
            $spec = UiFieldTypes::for($field)->filter($field);
            if (isset($spec['options']) && $spec['options'] !== []) {
                $options = $spec['options'];
                if (isset($counts[$field->name])) {
                    // "PHP (12)": how many records each choice holds; a choice
                    // nothing points at still shows, as "(0)".
                    $options = array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => sprintf('%s (%d)', $o['label'], $counts[$field->name][$o['value']] ?? 0)], $options);
                }
                $all[$field->name] = [['value' => '', 'label' => '(any)'], ...$options];
            }
        }

        return $all;
    }

    /**
     * A range filter's ends checked against the field's type before they reach
     * SQL — a number must be a number, a day a real day — and a date-only end
     * on a moment widened to the whole day ("to 2026-10-06" includes that
     * evening; compared as written it stopped at midnight).
     */
    public static function normalizeRanges(CollectionCriteria $criteria, UiFieldSet $fields): CollectionCriteria
    {
        $terms = [];
        $changed = false;
        foreach ($criteria->filter->terms as $term) {
            $field = $fields->get($term->field);
            if ($field === null || !in_array($term->operator, [FilterOperator::Gte, FilterOperator::Lte], true) || !is_string($term->value)) {
                $terms[] = $term;
                continue;
            }
            $value = trim($term->value);
            $day = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
            $valid = match ($field->type) {
                'integer', 'decimal' => is_numeric($value),
                'date' => $day,
                'datetime' => $day || \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) !== false || \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value) !== false,
                default => true,
            };
            if (!$valid) {
                throw new InvalidFilterException(rawValue: $term->field . ':' . $term->operator->value . ':' . $value, reason: sprintf('"%s" is not a %s', $value, $field->type === 'integer' || $field->type === 'decimal' ? 'number' : 'date'));
            }
            if ($field->type === 'datetime') {
                $value = $day
                    ? $value . ($term->operator === FilterOperator::Lte ? ' 23:59:59' : ' 00:00:00')
                    : str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
            }
            $changed = $changed || $value !== $term->value;
            $terms[] = new FilterTerm($term->field, $term->operator, $value);
        }

        return !$changed ? $criteria : new CollectionCriteria(
            page: $criteria->page,
            sort: $criteria->sort,
            filter: new CollectionFilterRequest($terms),
            q: $criteria->q,
            searchFields: $criteria->searchFields,
            cursor: $criteria->cursor,
            policy: $criteria->policy,
            pageWasRequested: $criteria->pageWasRequested,
        );
    }
}
