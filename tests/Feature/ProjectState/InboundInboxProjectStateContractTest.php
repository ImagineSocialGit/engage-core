<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundInboxProjectStateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_state_and_related_person_round_trip_with_the_inbound_section(): void
    {
        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);

        $contact = Contact::factory()->create([
            'name' => 'Related Person',
            'email' => 'related@example.test',
        ]);

        $reviewedAt = now()->subHour()->startOfSecond();
        $extractionAttemptedAt = now()->subMinutes(30)->startOfSecond();

        $message = InboundMessage::query()->create([
            'related_contact_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'evt-inbox-project-state',
            'provider_message_id' => 'msg-inbox-project-state',
            'from_type' => 'email',
            'from_value' => 'notifications@vendor.example',
            'reply_to_value' => 'related@example.test',
            'to_type' => 'email',
            'to_value' => 'vendor-updates@inbound.example.test',
            'subject' => 'Vendor update',
            'body' => 'Please review this vendor update.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'contact_extraction_status' =>
                InboundMessage::CONTACT_EXTRACTION_SUCCEEDED,
            'contact_extraction_definition_hash' => str_repeat('a', 64),
            'contact_extraction_error' => null,
            'contact_extraction_attempted_at' => $extractionAttemptedAt,
            'inbox_status' => InboundMessage::INBOX_STATUS_REVIEWED,
            'reviewed_at' => $reviewedAt,
            'received_at' => $reviewedAt->copy()->subMinute(),
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(
            (int) config('project_state.version'),
            $document['version'],
        );
        $this->assertSame(
            (int) config('project_state.sections.inbound_messaging.version'),
            $document['sections']['inbound_messaging']['version'],
        );

        DB::table('inbound_messages')->delete();
        DB::table('contacts')->delete();

        $report = $projectState->validate($document);
        $this->assertTrue($report['valid'], implode(' ', $report['errors']));
        $this->assertTrue($projectState->import($document)['applied']);

        $restored = InboundMessage::query()
            ->where('provider_event_id', 'evt-inbox-project-state')
            ->sole();
        $restoredContact = Contact::query()
            ->where('email', 'related@example.test')
            ->sole();

        $this->assertSame($message->getKey(), $restored->getKey());
        $this->assertSame(
            $restoredContact->getKey(),
            $restored->related_contact_id,
        );
        $this->assertSame(
            InboundMessage::INBOX_STATUS_REVIEWED,
            $restored->inbox_status,
        );
        $this->assertSame(
            $reviewedAt->toISOString(),
            $restored->reviewed_at?->toISOString(),
        );
        $this->assertNull($restored->completed_at);
        $this->assertSame('related@example.test', $restored->reply_to_value);
        $this->assertSame(
            InboundMessage::CONTACT_EXTRACTION_SUCCEEDED,
            $restored->contact_extraction_status,
        );
        $this->assertSame(
            str_repeat('a', 64),
            $restored->contact_extraction_definition_hash,
        );
        $this->assertSame(
            $extractionAttemptedAt->toISOString(),
            $restored->contact_extraction_attempted_at?->toISOString(),
        );
    }
}