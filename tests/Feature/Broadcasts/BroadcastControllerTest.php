<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
        ]);
    }

    public function test_it_lists_broadcasts_and_opt_in_invitations_as_separate_sections(): void
    {
        $user = User::factory()->create();

        Broadcast::factory()->create([
            'name' => 'Weekly update',
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);

        Broadcast::factory()->create([
            'name' => 'Imported opt-in invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'));

        $response->assertOk();
        $response->assertSee(
            'name="broadcast_type" value="'.Broadcast::BROADCAST_TYPE_REGULAR.'"',
            false,
        );
        $response->assertSee(
            'name="broadcast_type" value="'.Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION.'"',
            false,
        );
        $response->assertSee('Weekly update');
        $response->assertSee('Imported opt-in invitation');
    }

    public function test_it_creates_a_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Weekly update',
                'subject' => 'This week',
                'body' => 'Here is the update.',
                'recipient_filter_type' => 'all',
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame($user->id, $broadcast->user_id);
        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame(Broadcast::BROADCAST_TYPE_REGULAR, $broadcast->meta['broadcast_type']);
        $this->assertSame(Broadcast::DEFAULT_MESSAGE_TYPE, $broadcast->message_type);
        $this->assertSame('Weekly update', $broadcast->name);
        $this->assertSame('This week', $broadcast->payload['subject']);
        $this->assertSame('Here is the update.', $broadcast->payload['body']);
        $this->assertSame(['type' => 'all'], $broadcast->recipient_filter);
    }

    public function test_it_creates_a_draft_broadcast_with_prior_broadcast_exclusions(): void
    {
        $user = User::factory()->create();

        $previousBroadcast = Broadcast::factory()->completed()->create([
            'name' => 'Prior SMS send',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Follow-up email',
                'subject' => 'Follow-up',
                'body' => 'Here is the follow-up.',
                'recipient_filter_type' => 'all',
                'exclude_broadcast_ids' => [$previousBroadcast->id],
                'exclude_broadcast_statuses' => [
                    BroadcastRecipient::STATUS_SCHEDULED,
                    BroadcastRecipient::STATUS_SENT,
                ],
            ]);

        $broadcast = Broadcast::query()
            ->where('name', 'Follow-up email')
            ->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame(['type' => 'all'], array_intersect_key($broadcast->recipient_filter, ['type' => true]));
        $this->assertSame([$previousBroadcast->id], data_get($broadcast->recipient_filter, 'exclude.broadcast_ids'));
        $this->assertSame([
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
        ], data_get($broadcast->recipient_filter, 'exclude.statuses'));
    }

    public function test_it_creates_a_draft_opt_in_invitation(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'intent' => 'draft',
                'channel' => 'email',
                'name' => 'Imported opt-in invitation',
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Please confirm your communication preferences.',
                'recipient_filter_type' => 'imported',
            ]);

        $response->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame($user->id, $broadcast->user_id);
        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame(Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION, $broadcast->meta['broadcast_type']);
        $this->assertSame(Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION, $broadcast->message_type);
        $this->assertSame(Broadcast::PERMISSION_INVITATION_DISPATCH_KEY, $broadcast->dispatch_key);
        $this->assertSame('email', $broadcast->channel);
        $this->assertSame('transactional', $broadcast->purpose);
        $this->assertSame('permission_invitation', $broadcast->scope);
        $this->assertSame(['type' => 'imported'], $broadcast->recipient_filter);
    }

    public function test_permission_invitation_create_request_ignores_submitted_recipient_filter(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'intent' => 'draft',
                'name' => 'Imported opt-in invitation',
                'channel' => 'email',
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Please confirm your communication preferences.',
                'recipient_filter_type' => 'contact_ids',
                'contact_ids' => [$contact->id],
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame(['type' => 'imported'], $broadcast->recipient_filter);
        $this->assertSame('email', $broadcast->channel);
        $this->assertSame('transactional', $broadcast->purpose);
        $this->assertSame('permission_invitation', $broadcast->scope);
        $this->assertSame(Broadcast::PERMISSION_INVITATION_DISPATCH_KEY, $broadcast->dispatch_key);
        $this->assertSame(Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION, $broadcast->message_type);
    }

    public function test_it_schedules_a_broadcast_from_create_request(): void
    {
        $user = User::factory()->create();

        $this->mock(ScheduleBroadcastAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Broadcast $broadcast): Broadcast {
                $broadcast->forceFill([
                    'status' => Broadcast::STATUS_SCHEDULED,
                    'recipient_count' => 0,
                    'scheduled_count' => 0,
                ])->save();

                return $broadcast->refresh();
            });

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'schedule',
                'name' => 'Weekly update',
                'subject' => 'This week',
                'body' => 'Here is the update.',
                'recipient_filter_type' => 'tag',
                'recipient_tag' => 'homebuyer',
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
        $this->assertSame('tag', $broadcast->recipient_filter['type']);
        $this->assertSame(['homebuyer'], $broadcast->recipient_filter['tags']);
    }

    public function test_regular_broadcast_form_hides_sms_when_sms_is_not_visible_for_broadcasts(): void
    {
        $user = User::factory()->create();

        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'));

        $response->assertOk();
        $response->assertSee('name="channel" value="email"', false);
        $response->assertDontSee('<option value="sms">', false);
    }

    public function test_regular_broadcast_form_shows_sms_when_sms_is_visible_for_broadcasts(): void
    {
        $user = User::factory()->create();

        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'));

        $response->assertOk();
        $response->assertSee('name="channel"', false);
        $response->assertSee('value="sms"', false);
        $response->assertSee('name="message"', false);
    }

    public function test_it_creates_a_draft_sms_broadcast_when_sms_is_visible_for_broadcasts(): void
    {
        $user = User::factory()->create();

        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'channel' => 'sms',
                'name' => 'SMS update',
                'message' => 'This is an SMS broadcast.',
                'recipient_filter_type' => 'all',
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame('sms', $broadcast->channel);
        $this->assertSame(SmsPayload::class, $broadcast->payload_class);
        $this->assertSame('This is an SMS broadcast.', $broadcast->payload['message']);
        $this->assertArrayNotHasKey('subject', $broadcast->payload);
        $this->assertArrayNotHasKey('body', $broadcast->payload);
    }

    public function test_it_rejects_sms_broadcast_when_sms_is_not_visible_for_broadcasts(): void
    {
        $user = User::factory()->create();

        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('crm.broadcasts.index'))
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'channel' => 'sms',
                'name' => 'SMS update',
                'message' => 'This is an SMS broadcast.',
                'recipient_filter_type' => 'all',
            ]);

        $response->assertRedirect(route('crm.broadcasts.index'));
        $response->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('broadcasts', 0);
    }

    public function test_permission_invitation_ignores_submitted_sms_channel(): void
    {
        $user = User::factory()->create();

        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
            'messaging.channel_availability.sms.surfaces.permission_invitations' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'intent' => 'draft',
                'channel' => 'sms',
                'name' => 'Imported opt-in invitation',
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Please confirm your communication preferences.',
                'recipient_filter_type' => 'imported',
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame('email', $broadcast->channel);
        $this->assertSame(Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION, $broadcast->message_type);
    }

    public function test_it_shows_a_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->scheduled()->create([
            'name' => 'Weekly update',
        ]);

        $contact = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
        ]);

        BroadcastRecipient::factory()->scheduled([1])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertSee('Weekly update');
        $response->assertSee('Jane Lead');
        $response->assertSee('jane@example.test');
    }

    public function test_it_shows_sms_broadcast_recipient_outcomes(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->scheduled()->create([
            'name' => 'SMS update',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'payload_class' => SmsPayload::class,
            'payload' => [
                'message' => 'This is an SMS broadcast.',
            ],
            'recipient_count' => 3,
            'scheduled_count' => 1,
        ]);

        $scheduledContact = Contact::factory()->create([
            'name' => 'Scheduled Lead',
            'phone' => '+15555550123',
        ]);

        $skippedContact = Contact::factory()->create([
            'name' => 'Skipped Lead',
            'phone' => '+15555550124',
        ]);

        $failedContact = Contact::factory()->create([
            'name' => 'Failed Lead',
            'phone' => '+15555550125',
        ]);

        BroadcastRecipient::factory()->scheduled([1])->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $scheduledContact->id,
        ]);

        BroadcastRecipient::factory()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $skippedContact->id,
            'status' => BroadcastRecipient::STATUS_SKIPPED,
            'skip_reason' => 'broadcast_channel_unavailable',
            'scheduled_message_ids' => null,
        ]);

        BroadcastRecipient::factory()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $failedContact->id,
            'status' => BroadcastRecipient::STATUS_FAILED,
            'skip_reason' => null,
            'meta' => [
                'delivery' => [
                    'failure_reason' => 'provider_rejected',
                ],
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertSee('SMS update');
        $response->assertSee('This is an SMS broadcast.');
        $response->assertSee('+15555550123');
        $response->assertSee('+15555550124');
        $response->assertSee('+15555550125');

        $response->assertSee('broadcast channel unavailable');
        $response->assertSee('provider rejected');
    }

    public function test_it_shows_an_opt_in_invitation_with_distinct_copy(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Imported opt-in invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertSee('Imported opt-in invitation');
        $response->assertSee('transactional / permission_invitation');
    }

    public function test_it_shows_the_edit_form_for_a_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Draft broadcast',
            'payload' => [
                'subject' => 'Draft subject',
                'body' => 'Draft body',
            ],
            'recipient_filter' => [
                'type' => 'tag',
                'tags' => ['homebuyer'],
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.edit', $broadcast));

        $response->assertOk();
        $response->assertSee('name="name"', false);
        $response->assertSee('Draft broadcast');
        $response->assertSee('Draft subject');
        $response->assertSee('Draft body');
        $response->assertSee('homebuyer');
    }

    public function test_it_shows_the_edit_form_for_an_opt_in_invitation_with_locked_recipients(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Imported opt-in invitation',
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.edit', $broadcast));

        $response->assertOk();
        $response->assertSee('name="recipient_filter_type"', false);
        $response->assertSee('value="imported"', false);
        $response->assertSee('value="import_batch"', false);
        $response->assertSee('name="import_batch_ids[]"', false);
    }

    public function test_it_updates_a_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Old name',
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body',
            ],
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated broadcast',
                'subject' => 'Updated subject',
                'body' => 'Updated body',
                'recipient_filter_type' => 'tag',
                'recipient_tag' => 'realtor',
                'send_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('Updated broadcast', $broadcast->name);
        $this->assertSame('Updated subject', $broadcast->payload['subject']);
        $this->assertSame('Updated body', $broadcast->payload['body']);
        $this->assertSame('tag', $broadcast->recipient_filter['type']);
        $this->assertSame(['realtor'], $broadcast->recipient_filter['tags']);
        $this->assertNotNull($broadcast->send_at);
    }

    public function test_it_updates_a_draft_broadcast_with_prior_broadcast_exclusions(): void
    {
        $user = User::factory()->create();

        $previousBroadcast = Broadcast::factory()->completed()->create([
            'name' => 'Prior SMS send',
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated broadcast',
                'subject' => 'Updated subject',
                'body' => 'Updated body',
                'recipient_filter_type' => 'tag',
                'recipient_tag' => 'homebuyer',
                'exclude_broadcast_ids' => [$previousBroadcast->id],
                'exclude_broadcast_statuses' => [
                    BroadcastRecipient::STATUS_SCHEDULED,
                    BroadcastRecipient::STATUS_SENT,
                ],
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('tag', $broadcast->recipient_filter['type']);
        $this->assertSame(['homebuyer'], $broadcast->recipient_filter['tags']);
        $this->assertSame([$previousBroadcast->id], data_get($broadcast->recipient_filter, 'exclude.broadcast_ids'));
        $this->assertSame([
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
        ], data_get($broadcast->recipient_filter, 'exclude.statuses'));
    }

    public function test_it_updates_an_opt_in_invitation_without_changing_the_imported_recipient_filter(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Old invitation',
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body',
            ],
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated invitation',
                'subject' => 'Updated subject',
                'body' => 'Updated body',
                'recipient_filter_type' => 'all',
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('Updated invitation', $broadcast->name);
        $this->assertSame('Updated subject', $broadcast->payload['subject']);
        $this->assertSame('Updated body', $broadcast->payload['body']);
        $this->assertSame(['type' => 'imported'], $broadcast->recipient_filter);
    }

    public function test_it_creates_a_draft_broadcast_for_selected_contacts(): void
    {
        $user = User::factory()->create();

        $included = Contact::factory()->create();
        $other = Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Selected contacts update',
                'subject' => 'Selected contacts',
                'body' => 'This goes to selected contacts.',
                'recipient_filter_type' => 'contact_ids',
                'contact_ids' => [$included->id],
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame('contact_ids', $broadcast->recipient_filter['type']);
        $this->assertSame([$included->id], $broadcast->recipient_filter['contact_ids']);
        $this->assertNotContains($other->id, $broadcast->recipient_filter['contact_ids']);
    }

    public function test_it_updates_a_draft_broadcast_to_selected_contacts(): void
    {
        $user = User::factory()->create();

        $included = Contact::factory()->create();
        $other = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated selected contacts',
                'subject' => 'Updated selected contacts',
                'body' => 'This now goes to selected contacts.',
                'recipient_filter_type' => 'contact_ids',
                'contact_ids' => [$included->id],
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('contact_ids', $broadcast->recipient_filter['type']);
        $this->assertSame([$included->id], $broadcast->recipient_filter['contact_ids']);
        $this->assertNotContains($other->id, $broadcast->recipient_filter['contact_ids']);
    }

    public function test_it_shows_selected_contact_recipient_filter_details(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Selected contacts update',
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [$contact->id],
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertSee('Jane Lead');
        $response->assertSee('jane@example.test');
    }

    public function test_it_does_not_show_the_edit_form_for_a_non_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->scheduled()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.edit', $broadcast));

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));
    }

    public function test_it_does_not_update_a_non_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->scheduled()->create([
            'name' => 'Original broadcast',
            'payload' => [
                'subject' => 'Original subject',
                'body' => 'Original body',
            ],
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Changed broadcast',
                'subject' => 'Changed subject',
                'body' => 'Changed body',
                'recipient_filter_type' => 'tag',
                'recipient_tag' => 'changed',
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('Original broadcast', $broadcast->name);
        $this->assertSame('Original subject', $broadcast->payload['subject']);
        $this->assertSame('Original body', $broadcast->payload['body']);
        $this->assertSame(['type' => 'all'], $broadcast->recipient_filter);
    }

    public function test_it_schedules_an_existing_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
        ]);

        $this->mock(ScheduleBroadcastAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Broadcast $broadcast): Broadcast {
                $broadcast->forceFill([
                    'status' => Broadcast::STATUS_SCHEDULED,
                ])->save();

                return $broadcast->refresh();
            });

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.schedule', $broadcast), [
                'send_at' => now()->addHour()->format('Y-m-d\TH:i'),
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
    }

    public function test_it_cancels_a_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->scheduled()->create();

        $this->mock(CancelBroadcastAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Broadcast $broadcast): Broadcast {
                $broadcast->forceFill([
                    'status' => Broadcast::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ])->save();

                return $broadcast->refresh();
            });

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.cancel', $broadcast));

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_CANCELLED, $broadcast->status);
        $this->assertNotNull($broadcast->cancelled_at);
    }

    public function test_it_shows_permission_invitation_eligibility_preview_on_show_page(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create([
            'source' => 'import',
            'email' => 'eligible@example.test',
        ]);

        $alreadyConsented = Contact::factory()->create([
            'source' => 'import',
            'email' => 'consented@example.test',
        ]);

        Contact::factory()->create([
            'source' => 'crm',
            'email' => 'not-imported@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Imported opt-in invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertViewHas(
            'permissionInvitationPreview',
            fn (array $preview): bool =>
                ($preview['imported_contacts_count'] ?? null) === 2
                && ($preview['ineligible_contacts_count'] ?? null) === 1
                && ($preview['eligible_contacts_count'] ?? null) === 1,
        );
    }

    public function test_it_shows_permission_invitation_eligibility_preview_on_edit_page(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create([
            'source' => 'import',
            'email' => 'eligible@example.test',
        ]);

        $alreadyConsented = Contact::factory()->create([
            'source' => 'import',
            'email' => 'consented@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Imported opt-in invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.edit', $broadcast));

        $response->assertOk();
        $response->assertViewHas(
            'permissionInvitationPreview',
            fn (array $preview): bool =>
                ($preview['imported_contacts_count'] ?? null) === 2
                && ($preview['ineligible_contacts_count'] ?? null) === 1
                && ($preview['eligible_contacts_count'] ?? null) === 1,
        );
    }

    public function test_it_shows_permission_invitation_eligibility_preview_on_index_page(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create([
            'source' => 'import',
            'email' => 'eligible@example.test',
        ]);

        $alreadyConsented = Contact::factory()->create([
            'source' => 'import',
            'email' => 'consented@example.test',
        ]);

        $alreadyInvited = Contact::factory()->create([
            'source' => 'import',
            'email' => 'invited@example.test',
        ]);

        Contact::factory()->create([
            'source' => 'crm',
            'email' => 'not-imported@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        ContactPermissionInvitation::query()->create([
            'contact_id' => $alreadyInvited->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subHour(),
            'sent_at' => now()->subMinutes(55),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'));

        $response->assertOk();
        $response->assertViewHas(
            'permissionInvitationPreview',
            fn (array $preview): bool =>
                ($preview['imported_contacts_count'] ?? null) === 3
                && ($preview['already_consented_count'] ?? null) === 1
                && ($preview['already_invited_count'] ?? null) === 1
                && ($preview['eligible_contacts_count'] ?? null) === 1,
        );
    }

    public function test_it_blocks_scheduling_permission_invitation_when_no_imported_contacts_are_eligible(): void
    {
        $user = User::factory()->create();

        $alreadyConsented = Contact::factory()->create([
            'source' => 'import',
            'email' => 'consented@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $this->mock(ScheduleBroadcastAction::class)
            ->shouldNotReceive('handle');

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'intent' => 'schedule',
                'name' => 'Imported opt-in invitation',
                'channel' => 'email',
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Please confirm your communication preferences.',
                'recipient_filter_type' => 'imported',
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));
        $response->assertSessionHas('error');

        $broadcast->refresh();

        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame(0, $broadcast->recipient_count);
        $this->assertSame(0, $broadcast->scheduled_count);
    }

    public function test_it_creates_a_draft_opt_in_invitation_for_selected_import_batches(): void
    {
        $user = User::factory()->create();

        $batch = ContactImportBatch::factory()->create([
            'name' => 'June import',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
                'intent' => 'draft',
                'name' => 'June import opt-in invitation',
                'channel' => 'email',
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Please confirm your communication preferences.',
                'recipient_filter_type' => 'import_batch',
                'import_batch_ids' => [$batch->id],
            ]);

        $broadcast = Broadcast::query()->first();

        $this->assertNotNull($broadcast);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame(Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION, $broadcast->meta['broadcast_type']);
        $this->assertSame(Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION, $broadcast->message_type);
        $this->assertSame([
            'type' => 'import_batch',
            'import_batch_ids' => [$batch->id],
        ], $broadcast->recipient_filter);
    }

    public function test_it_updates_an_opt_in_invitation_to_selected_import_batches(): void
    {
        $user = User::factory()->create();

        $batch = ContactImportBatch::factory()->create([
            'name' => 'June import',
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Old invitation',
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body',
            ],
            'recipient_filter' => [
                'type' => 'imported',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated invitation',
                'subject' => 'Updated subject',
                'body' => 'Updated body',
                'recipient_filter_type' => 'import_batch',
                'import_batch_ids' => [$batch->id],
            ]);

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();

        $this->assertSame('Updated invitation', $broadcast->name);
        $this->assertSame([
            'type' => 'import_batch',
            'import_batch_ids' => [$batch->id],
        ], $broadcast->recipient_filter);
    }

    public function test_it_shows_import_batch_recipient_filter_details_for_opt_in_invitation(): void
    {
        $user = User::factory()->create();

        $batch = ContactImportBatch::factory()->create([
            'name' => 'June CSV Import',
            'original_filename' => 'june-leads.csv',
            'successful_count' => 12,
            'failed_count' => 1,
        ]);

        $broadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_DRAFT,
            'name' => 'Batch opt-in invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => [
                'type' => 'import_batch',
                'import_batch_ids' => [$batch->id],
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast));

        $response->assertOk();
        $response->assertSee('June CSV Import');
        $response->assertSee('june-leads.csv');
        $response->assertSee('12 successful');
        $response->assertSee('1 failed');
    }

}