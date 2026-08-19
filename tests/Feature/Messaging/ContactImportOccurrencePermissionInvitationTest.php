<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Actions\CreateContactPermissionInvitationsForImportBatchAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactImportOccurrencePermissionInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'messaging.email.from.marketing.address' => 'marketing@example.test',
            'messaging.email.from.marketing.name' => 'Marketing',
            'messaging.email.from.default.address' => 'hello@example.test',
            'messaging.email.from.default.name' => 'Example',
        ]);
    }

    public function test_permission_invitations_use_historical_occurrence_membership_after_a_later_import(): void
    {
        $firstBatch = ContactImportBatch::factory()->create();
        $secondBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'overlap@example.test',
            'contact_import_batch_id' => $secondBatch->id,
            'source' => 'import',
        ]);

        ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $firstBatch->id,
            'contact_id' => $contact->id,
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => 'overlap@example.test',
            'row_fingerprint' => hash('sha256', 'first-import-row'),
            'meta' => [],
        ]);

        ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $secondBatch->id,
            'contact_id' => $contact->id,
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_UPDATED,
            'identity_type' => 'email',
            'identity_value' => 'overlap@example.test',
            'row_fingerprint' => hash('sha256', 'second-import-row'),
            'meta' => [],
        ]);

        $result = app(CreateContactPermissionInvitationsForImportBatchAction::class)
            ->handle($firstBatch);

        $this->assertEquals([
            'eligible' => 1,
            'scheduled' => 1,
            'skipped' => 0,
        ], $result);

        $this->assertSame(1, ScheduledMessage::query()
            ->where('recipient_type', $contact->getMorphClass())
            ->where('recipient_id', $contact->id)
            ->where('context_type', $firstBatch->getMorphClass())
            ->where('context_id', $firstBatch->id)
            ->count());
    }
}