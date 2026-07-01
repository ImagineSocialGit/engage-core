<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
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

    public function test_it_lists_broadcasts(): void
    {
        $user = User::factory()->create();

        Broadcast::factory()->create([
            'name' => 'Weekly update',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'));

        $response->assertOk();
        $response->assertSee('Broadcasts');
        $response->assertSee('Weekly update');
    }

    public function test_it_creates_a_draft_broadcast(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'intent' => 'draft',
                'name' => 'Weekly update',
                'subject' => 'This week',
                'body' => 'Here is the update.',
                'recipient_filter_type' => 'all',
            ]);

        $broadcast = Broadcast::query()->first();

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame($user->id, $broadcast->user_id);
        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame('Weekly update', $broadcast->name);
        $this->assertSame('This week', $broadcast->payload['subject']);
        $this->assertSame('Here is the update.', $broadcast->payload['body']);
        $this->assertSame(['type' => 'all'], $broadcast->recipient_filter);
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
                'intent' => 'schedule',
                'name' => 'Weekly update',
                'subject' => 'This week',
                'body' => 'Here is the update.',
                'recipient_filter_type' => 'tag',
                'recipient_tag' => 'homebuyer',
            ]);

        $broadcast = Broadcast::query()->first();

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->status);
        $this->assertSame('tag', $broadcast->recipient_filter['type']);
        $this->assertSame(['homebuyer'], $broadcast->recipient_filter['tags']);
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
        $response->assertSee('Edit Broadcast Draft');
        $response->assertSee('Draft broadcast');
        $response->assertSee('Draft subject');
        $response->assertSee('Draft body');
        $response->assertSee('homebuyer');
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
}