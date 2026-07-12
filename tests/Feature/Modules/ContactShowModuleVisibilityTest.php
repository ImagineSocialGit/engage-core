<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\ForceStagingAccess;
use App\Modules\Core\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactShowModuleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_hides_webinar_history_when_webinars_module_is_disabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'tasks',
            'campaigns',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->id)
            ->assertOk()
            ->assertDontSee('Webinar History');
    }

    public function test_contact_show_hides_tasks_when_tasks_module_is_disabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'webinars',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->id)
            ->assertOk()
            ->assertDontSee('Add Task')
            ->assertDontSee('Create Task');
    }

    public function test_contact_show_hides_messages_when_messaging_module_is_disabled(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'webinars',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->id)
            ->assertOk()
            ->assertDontSee('Messages & consent')
            ->assertDontSee('data-module-panel="messaging"', false);
    }

    public function test_contact_show_uses_module_wayfinding_hooks_for_enabled_runtime_sections(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'tasks',
            'campaigns',
            'webinars',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->id)
            ->assertOk()
            ->assertSee('data-module-panel="core"', false)
            ->assertSee('data-module-panel="tasks"', false)
            ->assertSee('data-module-panel="messaging"', false)
            ->assertSee('data-module-panel="webinars"', false);
    }

    public function test_contact_show_uses_plain_language_for_follow_up_visibility_sections(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'campaigns',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/'.config('contacts.routes.plural').'/'.$contact->id)
            ->assertOk()
            ->assertSee('Automatic follow-ups')
            ->assertSee('Follow-up sequences')
            ->assertSee('Messages already handled')
            ->assertDontSee('FlowRoutes')
            ->assertDontSee('Flow Routes');
    }

}