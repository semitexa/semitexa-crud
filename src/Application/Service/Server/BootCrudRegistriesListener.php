<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Crud\Application\Service\Action\CrudActionHandlers;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use Semitexa\Crud\Application\Service\Settings\SettingsPages;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\Crud\Attribute\AsCrudAction;
use Semitexa\Crud\Attribute\AsSettingsPage;

/**
 * Registers the application's #[AsCrud] screens and #[AsSettingsPage] pages by
 * id at worker boot (after
 * platform-ui's registries, priority -5), so a form's signed screen id, the
 * navigation and the palette all resolve the same screens — and two screens
 * with one id stop the worker instead of shadowing each other. Their
 * #[AsCrudAction] handlers are registered first, so a screen naming code that
 * is not one stops it too.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -4,
    requiresContainer: true,
)]
final class BootCrudRegistriesListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function handle(ServerLifecycleContext $context): void
    {
        // Handlers first: a screen naming one that is not registered fails here.
        CrudActionHandlers::discover(
            $this->classDiscovery->findClassesWithAttribute(AsCrudAction::class),
            fn (string $class): object => RequestScopedContainer::forCurrentExecution($this->container)->get($class),
        );
        CrudScreens::discover($this->classDiscovery->findClassesWithAttribute(AsCrud::class));
        SettingsPages::discover($this->classDiscovery->findClassesWithAttribute(AsSettingsPage::class));
    }
}
