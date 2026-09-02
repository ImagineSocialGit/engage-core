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

    public function test_workspace_exposes_existing_routes_as_plain_language_addresses(): void
    {
        InboundEmailRoute::query()->create([
            'key' => 'website_forms',
            'local_part' => 'website-forms',
            'label' => 'Website Forms',
            'source' => 'integration',
            'context_key' => 'website_form',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.inbound-messaging.email-routes.index'))
            ->assertOk()
            ->assertSee('data-inbound-email-routes-workspace', false)
            ->assertSee('data-inbound-email-route-create', false)
            ->assertSee('data-inbound-email-route-editor', false)
            ->assertSee('website-forms@inbound.example.test')
            ->assertSee('Website Forms')
            ->assertDontSee('name="key"', false)
            ->assertDontSee('name="context_key"', false);
    }

    public function test_operator_can_create_update_and_disable_address_without_authoring_internal_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.inbound-messaging.email-routes.store'), [
                'form_mode' => 'create',
                'local_part' => 'WEBSITE-FORMS',
                'label' => 'Website Forms',
            ])
            ->assertRedirect();

        $route = InboundEmailRoute::query()->sole();

        $this->assertSame('website_forms', $route->key);
        $this->assertSame('website-forms', $route->local_part);
        $this->assertSame('Website Forms', $route->label);
        $this->assertSame('crm', $route->source);
        $this->assertNull($route->context_key);
        $this->assertTrue($route->is_active);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.email-routes.update', $route),
                [
                    'local_part' => 'updated-website-forms',
                    'label' => 'Updated Website Forms',
                ],
            )
            ->assertRedirect();

        $route->refresh();

        $this->assertSame('website_forms', $route->key);
        $this->assertSame('updated-website-forms', $route->local_part);
        $this->assertSame('Updated Website Forms', $route->label);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.email-routes.state', $route),
                ['is_active' => false],
            )
            ->assertRedirect();

        $this->assertFalse($route->refresh()->is_active);
    }

    public function test_internal_key_generation_stays_unique_without_operator_input(): void
    {
        $user = User::factory()->create();

        foreach (['website-one', 'website-two'] as $localPart) {
            $this->actingAs($user)
                ->post(route('crm.inbound-messaging.email-routes.store'), [
                    'local_part' => $localPart,
                    'label' => 'Website Forms',
                ])
                ->assertRedirect();
        }

        $this->assertEqualsCanonicalizing(
            ['website_forms', 'website_forms_2'],
            InboundEmailRoute::query()->pluck('key')->all(),
        );
    }

    public function test_signed_reply_namespace_cannot_be_created_as_semantic_address(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('crm.inbound-messaging.email-routes.index'))
            ->post(route('crm.inbound-messaging.email-routes.store'), [
                'form_mode' => 'create',
                'local_part' => 'reply+manual',
                'label' => 'Bad reply address',
            ])
            ->assertRedirect(route('crm.inbound-messaging.email-routes.index'))
            ->assertSessionHasErrors('local_part');

        $this->assertDatabaseMissing('inbound_email_routes', [
            'local_part' => 'reply+manual',
        ]);
    }

    public function test_setup_validation_still_rejects_reserved_signed_reply_namespace_from_direct_data(): void
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