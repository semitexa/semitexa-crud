<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Dashboard;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Orm\Domain\Model\ResourceMetadata;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Query\Direction;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboardWidgetInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetPermissionInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetWatchesInterface;
use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/**
 * The records of a CRUD screen changed most recently (by its updated, else
 * created column), each a link to its view dialog. Shown only to who may read
 * the screen.
 */
abstract class RecentRecordsWidget implements UiDashboardWidgetInterface, UiWidgetPermissionInterface, UiWidgetWatchesInterface
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    /** @return class-string<CrudDefinition> */
    abstract public static function screen(): string;

    protected function limit(): int
    {
        return 5;
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
        $crud = $screen->crud();
        $metadata = $this->records->metadata($screen);
        $moment = $this->records->momentProperty($screen);
        $query = $this->records->scoped($screen);
        if ($moment !== null) {
            $query = $query->orderBy(ColumnRef::for($crud->model(), $moment), Direction::Desc);
        }

        $items = [];
        foreach ($query->limit($this->limit())->fetchAll() as $record) {
            $id = CrudRecords::idOf($record, $metadata);
            $when = $moment !== null ? $record->{$moment} : null;
            $items[] = [
                'title' => $screen->title($record, $id),
                'href' => $crud->path . '?view=' . rawurlencode($id),
                'meta' => $when instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone('UTC'))->format('M j, H:i') . ' UTC'
                    : '',
            ];
        }

        return UiWidget::list('Recent ' . mb_strtolower($crud->plural()), $items, sprintf('No %s yet.', mb_strtolower($crud->plural())))
            ->withLink($crud->path, 'All ' . mb_strtolower($crud->plural()));
    }
}
