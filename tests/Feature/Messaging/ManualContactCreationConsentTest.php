<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualContactCreationConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_manual_contact_records_transactional_and_marketing_permission_for_saved_channels(): void
    {
        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.runtime_supported', true);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'last_name' => 'Lead',
                'email' => 'jane@example.test',
                'phone' => '5551112222',
                'existing_relationship_confirmed' => '1',
            ]);

        $contact = Contact::query()
            ->where('email', 'jane@example.test')
            ->firstOrFail();

        $response->assertRedirect(route('crm.contacts.show', $contact));

        $consents = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->orderBy('channel')
            ->orderBy('purpose')
            ->get();

        $this->assertCount(4, $consents);
        $this->assertEqualsCanonicalizing(
            [
                'email:transactional',
                'email:marketing',
                'sms:transactional',
                'sms:marketing',
            ],
            $consents
                ->map(fn (MessageConsent $consent): string => $consent->channel->value.':'.$consent->purpose->value)
                ->all(),
        );

        foreach ($consents as $consent) {
            $this->assertSame('crm_manual_create', $consent->source);
            $this->assertSame('crm_manual_create', $consent->scope);
            $this->assertTrue(
                (bool) data_get($consent->meta, 'crm_manual_create.existing_relationship_confirmed'),
            );
            $this->assertSame(
                $user->getKey(),
                data_get($consent->meta, 'crm_manual_create.actor_user_id'),
            );
        }
    }

    public function test_manual_contact_requires_existing_relationship_confirmation_when_messaging_is_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'email' => 'jane@example.test',
            ])
            ->assertSessionHasErrors('existing_relationship_confirmed');

        $this->assertDatabaseMissing('contacts', [
            'email' => 'jane@example.test',
        ]);
    }

    public function test_email_only_manual_contact_records_only_email_permissions(): void
    {
        config()->set('messaging.channel_availability.email.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.runtime_supported', true);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'email' => 'jane@example.test',
                'existing_relationship_confirmed' => '1',
            ]);

        $contact = Contact::query()
            ->where('email', 'jane@example.test')
            ->firstOrFail();

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'source' => 'crm_manual_create',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'source' => 'crm_manual_create',
        ]);
        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
        ]);
    }

    public function test_readding_existing_contact_does_not_regrant_or_override_revoked_permission(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'jane@example.test',
            'first_name' => 'Jane',
        ]);

        ConsentRevocation::query()->create([
            'contact_id' => $contact->getKey(),
            'message_consent_id' => null,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'manual_request',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'revoked_at' => now()->subDay(),
            'source' => 'test_existing_revocation',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Janet',
                'email' => 'jane@example.test',
                'existing_relationship_confirmed' => '1',
            ]);

        $response->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseCount('message_consents', 0);
        $this->assertDatabaseCount('consent_revocations', 1);
        $this->assertSame(
            'Janet',
            $contact->refresh()->first_name,
        );
    }
}