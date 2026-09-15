<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDeletionMessagingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_contact_stops_pending_messaging_but_keeps_suppression_history(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'bounced@example.test',
        ]);

        $suppression = MessageSuppression::query()->create([
            'channel' => 'email',
            'destination' => 'bounced@example.test',
            'reason' => 'bounce',
            'provider' => 'resend',
            'suppressed_at' => now()->subMinute(),
        ]);

        $chain = MessageChain::query()->create([
            'key' => 'contact-delete-test',
            'name' => 'Contact delete test',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'contact-delete-test'),
            'published_at' => now(),
        ]);

        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'surface' => 'test',
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'contact-delete-test',
            'started_at' => now()->subMinute(),
            'next_action_at' => now()->addHour(),
        ]);

        $chainMessage = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => $enrollment->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        $directMessage = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => null,
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        $this
            ->actingAs($user)
            ->delete(route('crm.contacts.destroy', $contact))
            ->assertRedirect(route('crm.contacts.index'));

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $enrollment->refresh()->status,
        );
        $this->assertSame('contact_deleted', $enrollment->exit_reason_code);
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $chainMessage->refresh()->status,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $directMessage->refresh()->status,
        );

        $suppression->refresh();

        $this->assertNull($suppression->released_at);
        $this->assertDatabaseHas('message_suppressions', [
            'id' => $suppression->getKey(),
            'destination' => 'bounced@example.test',
        ]);
    }

    public function test_delivery_issue_panel_offers_release_or_contact_deletion(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'review@example.test',
        ]);

        MessageSuppression::query()->create([
            'channel' => 'email',
            'destination' => 'review@example.test',
            'reason' => 'bounce',
            'provider' => 'resend',
            'suppressed_at' => now()->subMinute(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('Release suppression')
            ->assertSee('Delete this Contact')
            ->assertSee('data-delivery-issue-delete-contact', false)
            ->assertSee(route('crm.contacts.destroy', $contact), false);
    }
}