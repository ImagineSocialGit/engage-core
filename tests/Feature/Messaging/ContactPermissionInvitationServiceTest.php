<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class ContactPermissionInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_rethrows_database_exception_when_no_competing_invitation_exists(): void
    {
        $contact = Contact::factory()->create([
            'source' => 'import',
            'email' => 'person@example.test',
        ]);
        $batch = ContactImportBatch::factory()->create();
        $message = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $batch->getMorphClass(),
            'context_id' => $batch->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'meta' => [
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        ContactPermissionInvitation::creating(function (): void {
            throw new QueryException(
                'mysql',
                'insert into contact_permission_invitations (...) values (...)',
                [],
                new PDOException('simulated invitation insert failure'),
            );
        });

        $thrown = null;

        try {
            app(ContactPermissionInvitationService::class)
                ->claimForScheduledMessage($message);
        } catch (QueryException $exception) {
            $thrown = $exception;
        } finally {
            ContactPermissionInvitation::flushEventListeners();
        }

        $this->assertInstanceOf(QueryException::class, $thrown);
        $this->assertDatabaseMissing('contact_permission_invitations', [
            'contact_id' => $contact->getKey(),
        ]);
    }
}