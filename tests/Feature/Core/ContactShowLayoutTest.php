<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactShowLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_config_can_feature_a_registered_contact_panel_in_the_right_rail(): void
    {
        config()->set('contacts.show.rail_panels', ['core.assignment']);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact));

        $response
            ->assertOk()
            ->assertSee('data-contact-right-rail', false)
            ->assertSee('data-contact-rail-panel="core.assignment"', false)
            ->assertDontSee('data-contact-main-panel="core.assignment"', false);
    }

    public function test_messaging_direct_send_defaults_to_the_contact_right_rail(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'messaging',
        ])));
        config()->set('messaging.channel_availability.email.surfaces.contact_direct_messages', true);

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'rail@example.test',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact));

        $response
            ->assertOk()
            ->assertSee('data-contact-right-rail', false)
            ->assertSee('data-contact-rail-panel="messaging-direct-message"', false)
            ->assertSee('data-contact-direct-message-panel', false)
            ->assertDontSee('data-contact-main-panel="messaging-direct-message"', false);
    }
}