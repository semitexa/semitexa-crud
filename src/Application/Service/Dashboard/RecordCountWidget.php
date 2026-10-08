<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Dashboard;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Orm\Domain\Model\ResourceMetadata;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboardWidgetInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetPermissionInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetWatchesInterface;
use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/**
 * How many records a CRUD screen holds, with a trend of how many were created
 * per day (when the model has a created column) and this week against last.
 * Shown only to who may read the screen; links to it.
 *
 *     #[AsService]
 *     #[AsDashboardWidget(dashboard: 'admin', order: 10)]
 *     final class ProductCount extends RecordCountWidget
 *     {
 *         public static function screen(): string { return ProductCrud::class; }
 *     }
 */
abstract class RecordCountWidget implements UiDashboardWidgetInterface, UiWidgetPermissionInterface, UiWidgetWatchesInterface
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    /** @return class-string<CrudDefinition> */
    abstract public static function screen(): string;

    /** The days the trend covers (a week against the week before needs 14). */
    protected function days(): int
    {
        return 14;
    }

    /** Count only some of the screen's records (only the active ones, say). */
    protected function narrow(ResourceModelQuery $query): ResourceModelQuery
    {
        return $query;
    }

    protected function label(CrudDefinition $screen): string
    {
        return $screen->crud()->plural();
    }

    public static function requiredPermission(): ?string
    {
        $class = static::screen();

        return (new $class())->crud()->permissionFor('read');
    }

    /** The screen's model: a write to it redraws the widget. */
    public static function watches(): array
    {
        $class = static::screen();

        return [ResourceMetadata::for((new $class())->crud()->model())->getResourceKey()];
    }

    public function widget(): UiWidget
    {
        $class = static::screen();
        $screen = new $class();
        $total = $this->narrow($this->records->scoped($screen))->count();
        $created = $this->records->momentProperty($screen, created: true);
        if ($created === null) {
            return UiWidget::stat($this->label($screen), number_format($total))->withLink($screen->crud()->path, 'All ' . mb_strtolower($screen->crud()->plural()));
        }

        $days = max(2, $this->days());
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $today->modify(sprintf('-%d days', $days - 1));
        $perDay = $this->narrow($this->records->scoped($screen))
            ->where(ColumnRef::for($screen->crud()->model(), $created), Operator::GreaterThanOrEquals, $from->format('Y-m-d 00:00:00'))
            ->countByDay(ColumnRef::for($screen->crud()->model(), $created));
        $series = [];
        for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
            $series[$day->format('M j')] = $perDay[$day->format('Y-m-d')] ?? 0;
        }
        $counts = array_values($series);
        $half = intdiv(count($counts), 2);
        $recent = array_sum(array_slice($counts, -$half));
        $before = array_sum(array_slice($counts, -2 * $half, $half));
        [$delta, $trend] = match (true) {
            $before === 0 && $recent === 0 => [null, 'flat'],
            $before === 0 => [sprintf('+%d new', $recent), 'up'],
            default => [sprintf('%+d%%', (int) round(($recent - $before) / $before * 100)), $recent > $before ? 'up' : ($recent < $before ? 'down' : 'flat')],
        };

        return UiWidget::stat(
            $this->label($screen),
            number_format($total),
            delta: $delta,
            trend: $trend,
            series: $series,
            caption: sprintf('%d created in the last %d days', array_sum($series), $days),
        )->withLink($screen->crud()->path, 'All ' . mb_strtolower($screen->crud()->plural()));
    }
}
