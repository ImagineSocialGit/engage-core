<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Actions\CreateContactPermissionInvitationsForImportBatchAction;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateContactPermissionInvitationsForImportBatchActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'messaging.email.from.marketing.address' => 'marketing@example.test',
            'messaging.email.from.marketing.name' => 'Marketing',
            'messaging.email.from.default.address' => 'hello@example.test',
            'messaging.email.from.default.name' => 'Example',
        ]);
    }

    public function test_it_schedules_only_one_permission_invitation_message_per_eligible_imported_contact(): void
    {
        $importBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $action = app(CreateContactPermissionInvitationsForImportBatchAction::class);

        $firstResult = $action->handle($importBatch);
        $secondResult = $action->handle($importBatch);

        $this->assertSame([
            'eligible' => 1,
            'scheduled' => 1,
            'skipped' => 0,
        ], $firstResult);

        $this->assertSame([
            'eligible' => 0,
            'scheduled' => 0,
            'skipped' => 1,
        ], $secondResult);

        $this->assertSame(1, ScheduledMessage::query()->count());

        $scheduledMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame($contact->getMorphClass(), $scheduledMessage->recipient_type);
        $this->assertSame($contact->id, $scheduledMessage->recipient_id);
        $this->assertSame(MessageChannel::Email->value, $scheduledMessage->channel);
        $this->assertSame(MessagePurpose::Marketing->value, $scheduledMessage->purpose);
        $this->assertSame('broadcast', $scheduledMessage->scope);
        $this->assertSame('imported_contact_permission_invitation', $scheduledMessage->message_type);
        $this->assertContains($scheduledMessage->status, [
            ScheduledMessage::STATUS_PENDING,
            ScheduledMessage::STATUS_SENT,
        ]);
    }

    public function test_it_skips_contacts_that_already_have_imported_contact_permission_invitations(): void
    {
        $importBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'token' => str()->random(64),
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(4),
            'meta' => [],
        ]);

        $result = app(CreateContactPermissionInvitationsForImportBatchAction::class)
            ->handle($importBatch);

        $this->assertSame([
            'eligible' => 0,
            'scheduled' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    public function test_it_skips_contacts_that_already_have_required_marketing_email_consent(): void
    {
        config([
            'messaging.permission_invitations.consent.scopes' => [
                'broadcast',
                'campaign',
            ],
        ]);

        $importBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        foreach (['broadcast', 'campaign'] as $scope) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => $scope,
                'consented_at' => now(),
                'source' => 'test',
            ]);
        }

        $result = app(CreateContactPermissionInvitationsForImportBatchAction::class)
            ->handle($importBatch);

        $this->assertSame([
            'eligible' => 0,
            'scheduled' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    public function test_it_skips_contacts_without_email_addresses(): void
    {
        $importBatch = ContactImportBatch::factory()->create();

        Contact::factory()->create([
            'email' => '',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $result = app(CreateContactPermissionInvitationsForImportBatchAction::class)
            ->handle($importBatch);

        $this->assertSame([
            'eligible' => 0,
            'scheduled' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    public function test_cancelled_pending_permission_invitation_messages_do_not_block_future_scheduling(): void
    {
        $importBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $pendingMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $importBatch->getMorphClass(),
            'context_id' => $importBatch->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
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

        $skipped = app(SkipScheduledMessagesAction::class)
            ->importedContactPermissionInvitationsForImportBatch($importBatch);

        $this->assertSame(1, $skipped);

        $pendingMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pendingMessage->status);
        $this->assertSame('permission_invitation_cancelled', $pendingMessage->skip_reason);

        $result = app(CreateContactPermissionInvitationsForImportBatchAction::class)
            ->handle($importBatch);

        $this->assertSame([
            'eligible' => 1,
            'scheduled' => 1,
            'skipped' => 0,
        ], $result);

        $this->assertSame(2, ScheduledMessage::query()->count());
        $this->assertSame(1, ScheduledMessage::query()->where('status', ScheduledMessage::STATUS_SKIPPED)->count());
        $this->assertSame(1, ScheduledMessage::query()
            ->where('status', '!=', ScheduledMessage::STATUS_SKIPPED)
            ->count());
    }
}