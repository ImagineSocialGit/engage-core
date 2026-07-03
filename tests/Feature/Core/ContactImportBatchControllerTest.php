<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactImportBatchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_contact_import_batches(): void
    {
        $user = User::factory()->create();

        $importBatch = ContactImportBatch::factory()->create([
            'name' => 'June CSV Import',
            'source' => 'crm_csv',
            'original_filename' => 'june-leads.csv',
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'imported_at' => now(),
            'successful_count' => 12,
            'failed_count' => 1,
        ]);

        Contact::factory()->count(2)->create([
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.index'));

        $response->assertOk();
        $response->assertSee('Import Batches');
        $response->assertSee('June CSV Import');
        $response->assertSee('june-leads.csv');
        $response->assertSee('Crm Csv');
        $response->assertSee('Completed');
        $response->assertSee('12');
    }

    public function test_it_shows_a_contact_import_batch_with_contacts(): void
    {
        $user = User::factory()->create();

        $importBatch = ContactImportBatch::factory()->create([
            'name' => 'June CSV Import',
            'source' => 'crm_csv',
            'original_filename' => 'june-leads.csv',
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'imported_at' => now(),
            'successful_count' => 1,
            'failed_count' => 0,
        ]);

        $included = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
            'phone' => '5551112222',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        Contact::factory()->create([
            'name' => 'Ignored Lead',
            'email' => 'ignored@example.test',
            'contact_import_batch_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.show', $importBatch));

        $response->assertOk();
        $response->assertSee('June CSV Import');
        $response->assertSee('june-leads.csv');
        $response->assertSee('Jane Lead');
        $response->assertSee('jane@example.test');
        $response->assertSee('5551112222');
        $response->assertDontSee('ignored@example.test');

        $this->assertSame($importBatch->id, $included->refresh()->contact_import_batch_id);
    }

    public function test_contacts_index_links_to_import_batches(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.index'));

        $response->assertOk();
        $response->assertSee('View Imports');
        $response->assertSee(route('crm.contacts.import-batches.index'), false);
    }

    public function test_it_cancels_pending_permission_invitation_messages_for_an_import_batch(): void
    {
        $user = User::factory()->create();

        $importBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $importBatch->getMorphClass(),
            'context_id' => $importBatch->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('crm.contacts.import-batches.permission-invitations.destroy', $importBatch));

        $response->assertRedirect(route('crm.contacts.import-batches.show', $importBatch));
        $response->assertSessionHas('success', '1 pending permission invitation message(s) cancelled.');

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertSame('permission_invitation_cancelled', $scheduledMessage->skip_reason);

        $this->assertDatabaseMissing('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
        ]);
    }
}