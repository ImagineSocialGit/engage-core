<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDirectMessageProjectStateContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_contact_backed_scheduled_message_context_is_supported_and_remapped(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'direct-message@example.test',
        ]);
        $sourceContactId = (int) $contact->getKey();

        $message = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'context_type' => Contact::class,
                'context_id' => $sourceContactId,
                'status' => ScheduledMessage::STATUS_SENT,
            ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $exportedMessage = collect(
            data_get($document, 'sections.messaging.tables.scheduled_messages', []),
        )->first(fn (mixed $row): bool =>
            is_array($row)
            && (int) ($row['id'] ?? 0) === (int) $message->getKey()
        );

        $this->assertIsArray($exportedMessage);
        $this->assertSame(Contact::class, $exportedMessage['recipient_type']);
        $this->assertSame($sourceContactId, (int) $exportedMessage['recipient_id']);
        $this->assertSame(Contact::class, $exportedMessage['context_type']);
        $this->assertSame($sourceContactId, (int) $exportedMessage['context_id']);

        $message->delete();
        $contact->delete();

        $report = $projectState->validate($document);
        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $result = $projectState->import($document);
        $this->assertTrue($result['applied']);

        $importedContact = Contact::query()
            ->where('email', 'direct-message@example.test')
            ->firstOrFail();

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => $message->getKey(),
            'recipient_type' => Contact::class,
            'recipient_id' => $importedContact->getKey(),
            'context_type' => Contact::class,
            'context_id' => $importedContact->getKey(),
        ]);
    }

    public function test_messaging_project_state_contract_declares_contact_as_a_scheduled_message_context(): void
    {
        $this->assertSame(
            7,
            config('project_state.sections.messaging.version'),
        );

        $references = config(
            'project_state.sections.messaging.tables.scheduled_messages.polymorphic_references',
            [],
        );

        $contextReference = collect($references)->first(
            fn (mixed $reference): bool =>
                is_array($reference)
                && ($reference['type_column'] ?? null) === 'context_type'
                && ($reference['id_column'] ?? null) === 'context_id',
        );

        $this->assertIsArray($contextReference);
        $this->assertSame(
            'contacts',
            $contextReference['targets'][Contact::class] ?? null,
        );
    }
}