<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Validation\InboundMessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailRouteWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_workspace_exposes_existing_routes_and_full_addresses(): void
    {
        $route = InboundEmailRoute::query()->create([
            'key' => 'arive_application',
            'local_part' => 'arive+application',
            'label' => 'Arive application',
            'source' => 'arive',
            'context_key' => 'application',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.inbound-messaging.email-routes.index'))
            ->assertOk()
            ->assertSee('data-inbound-email-routes-workspace', false)
            ->assertSee('data-inbound-email-route-create', false)
            ->assertSee('data-inbound-email-route-editor', false)
            ->assertSee('arive+application@inbound.example.test')
            ->assertSee($route->key)
            ->assertSee('Arive application');
    }

    public function test_operator_can_create_update_and_disable_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.inbound-messaging.email-routes.store'), [
                'form_mode' => 'create',
                'key' => 'arive_application',
                'local_part' => 'ARIVE+APPLICATION',
                'label' => 'Arive application',
                'source' => 'ARIVE',
                'context_key' => 'APPLICATION',
            ])
            ->assertRedirect();

        $route = InboundEmailRoute::query()
            ->where('key', 'arive_application')
            ->sole();

        $this->assertSame('arive+application', $route->local_part);
        $this->assertSame('arive', $route->source);
        $this->assertSame('application', $route->context_key);
        $this->assertTrue($route->is_active);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.email-routes.update', $route),
                [
                    'key' => 'arive_application',
                    'local_part' => 'arive+new-application',
                    'label' => 'Arive new application',
                    'source' => 'arive',
                    'context_key' => 'new_application',
                ],
            )
            ->assertRedirect();

        $route->refresh();

        $this->assertSame(
            'arive+new-application',
            $route->local_part,
        );
        $this->assertSame(
            'Arive new application',
            $route->label,
        );
        $this->assertSame(
            'new_application',
            $route->context_key,
        );

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.email-routes.state', $route),
                ['is_active' => false],
            )
            ->assertRedirect();

        $this->assertFalse($route->refresh()->is_active);
    }

    public function test_signed_reply_namespace_cannot_be_created_as_semantic_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('crm.inbound-messaging.email-routes.index'))
            ->post(route('crm.inbound-messaging.email-routes.store'), [
                'form_mode' => 'create',
                'key' => 'bad_reply_route',
                'local_part' => 'reply+manual',
                'label' => 'Bad reply route',
                'source' => 'manual',
                'context_key' => 'reply',
            ])
            ->assertRedirect(route('crm.inbound-messaging.email-routes.index'))
            ->assertSessionHasErrors('local_part');

        $this->assertDatabaseMissing('inbound_email_routes', [
            'key' => 'bad_reply_route',
        ]);
    }

    public function test_route_key_is_immutable_after_creation(): void
    {
        $route = InboundEmailRoute::query()->create([
            'key' => 'arive_application',
            'local_part' => 'arive+application',
            'label' => 'Arive application',
            'source' => 'arive',
            'context_key' => 'application',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('crm.inbound-messaging.email-routes.index'))
            ->patch(
                route('crm.inbound-messaging.email-routes.update', $route),
                [
                    'key' => 'arive_application_changed',
                    'local_part' => 'arive+application',
                    'label' => 'Arive application',
                    'source' => 'arive',
                    'context_key' => 'application',
                ],
            )
            ->assertRedirect(route('crm.inbound-messaging.email-routes.index'))
            ->assertSessionHasErrors('key');

        $this->assertSame(
            'arive_application',
            $route->refresh()->key,
        );
    }

    public function test_setup_validation_rejects_reserved_signed_reply_namespace_from_direct_data(): void
    {
        InboundEmailRoute::query()->create([
            'key' => 'bad_reply_route',
            'local_part' => 'reply+manual',
            'label' => 'Bad reply route',
            'source' => 'manual',
            'context_key' => 'reply',
            'is_active' => true,
        ]);

        $codes = collect(
            app(InboundMessagingSetupValidationContributor::class)->findings(),
        )
            ->pluck('code')
            ->values()
            ->all();

        $this->assertContains(
            'inbound_messaging.email_routes.local_part_reserved',
            $codes,
        );
    }
}