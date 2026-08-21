<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAudiencePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
        ]);
    }

    public function test_preview_reports_audience_size_consent_gap_and_prior_broadcast_overlap(): void
    {
        $user = User::factory()->create();
        $batch = ContactImportBatch::factory()->create();

        $first = Contact::factory()->create([
            'source' => 'Database',
            'contact_import_batch_id' => $batch->id,
            'email' => 'first@example.test',
        ]);

        $second = Contact::factory()->create([
            'source' => 'Database',
            'contact_import_batch_id' => $batch->id,
            'email' => 'second@example.test',
        ]);

        Contact::factory()->create([
            'source' => 'Other',
            'email' => 'other@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $second->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $previous = Broadcast::factory()->completed()->create([
            'name' => 'Previous Agent Note',
            'channel' => 'email',
        ]);

        BroadcastRecipient::query()->create([
            'broadcast_id' => $previous->id,
            'contact_id' => $first->id,
            'status' => BroadcastRecipient::STATUS_SENT,
        ]);

        BroadcastRecipient::query()->create([
            'broadcast_id' => $previous->id,
            'contact_id' => $second->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson(route('crm.broadcasts.audience-preview'), [
                'recipient_filter_type' => 'criteria',
                'recipient_criteria' => [
                    'source' => ['Database'],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('selected_count', 2)
            ->assertJsonPath('without_any_consent_count', 1)
            ->assertJsonPath('previous_broadcasts.0.id', $previous->id)
            ->assertJsonPath('previous_broadcasts.0.sent_count', 1)
            ->assertJsonPath('previous_broadcasts.0.scheduled_count', 1)
            ->assertJsonPath('previous_broadcasts.0.overlap_count', 2);
    }

    public function test_regular_broadcast_can_store_composite_criteria(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create([
            'source' => 'Database',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Agent Broadcast',
                'subject' => 'Agent update',
                'body' => 'Hello agents.',
                'recipient_filter_type' => 'criteria',
                'recipient_criteria' => [
                    'source' => ['Database'],
                ],
            ]);

        $broadcast = Broadcast::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));
        $this->assertEquals([
            'type' => 'criteria',
            'criteria' => [
                'source' => ['Database'],
            ],
        ], $broadcast->recipient_filter);
    }
}