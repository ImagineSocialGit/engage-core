<?php

namespace Tests\Feature\Events;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Events\Contracts\EventWorkspacePanelContributor;
use App\Modules\Events\Data\EventWorkspaceAction;
use App\Modules\Events\Data\EventWorkspacePanel;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Providers\EventsModuleServiceProvider;
use App\Modules\Events\Services\EventWorkspacePanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EventWorkspacePanelRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['events']);
        $this->app->register(EventsModuleServiceProvider::class, force: true);
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_registry_orders_bounded_workspace_panels_from_optional_contributors(): void
    {
        $event = Event::factory()->create();
        $registry = new EventWorkspacePanelRegistry([
            new class implements EventWorkspacePanelContributor
            {
                public function panels(Event $event): iterable
                {
                    yield new EventWorkspacePanel(
                        key: 'later',
                        title: 'Later Tool',
                        actions: [
                            new EventWorkspaceAction(
                                label: 'Open later tool',
                                url: '/later',
                            ),
                        ],
                        sortOrder: 80,
                    );

                    yield new EventWorkspacePanel(
                        key: 'first',
                        title: 'First Tool',
                        description: 'An optional Event consumer.',
                        actions: [
                            new EventWorkspaceAction(
                                label: 'Open first tool',
                                url: '/first',
                                style: EventWorkspaceAction::STYLE_PRIMARY,
                            ),
                        ],
                        sortOrder: 20,
                    );
                }
            },
        ]);

        $panels = $registry->panels($event);

        $this->assertSame(['first', 'later'], array_map(
            static fn (EventWorkspacePanel $panel): string => $panel->key,
            $panels,
        ));
        $this->assertSame('Open first tool', $panels[0]->actions[0]->label);
        $this->assertSame(EventWorkspaceAction::STYLE_PRIMARY, $panels[0]->actions[0]->style);
    }

    public function test_registry_rejects_duplicate_panel_keys(): void
    {
        $event = Event::factory()->create();
        $registry = new EventWorkspacePanelRegistry([
            new class implements EventWorkspacePanelContributor
            {
                public function panels(Event $event): iterable
                {
                    yield new EventWorkspacePanel(
                        key: 'duplicate',
                        title: 'First',
                    );
                }
            },
            new class implements EventWorkspacePanelContributor
            {
                public function panels(Event $event): iterable
                {
                    yield new EventWorkspacePanel(
                        key: 'duplicate',
                        title: 'Second',
                    );
                }
            },
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registered more than once');

        $registry->panels($event);
    }

}