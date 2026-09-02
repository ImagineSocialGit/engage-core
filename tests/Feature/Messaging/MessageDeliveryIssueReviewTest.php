<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\Dashboard\MessagingDeliveryIssuesDashboardPanelProvider;
use App\Modules\Messaging\Services\DeliveryIssues\MessageDeliveryIssueReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MessageDeliveryIssueReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_queue_only_contains_active_suppressions_for_current_contact_destinations(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'current@example.com',
            'phone' => '+15555550111',
        ]);

        $currentEmail = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'current@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
        );

        $currentSms = $this->suppression(
            channel: MessageChannel::Sms->value,
            destination: '+15555550111',
            reason: MessageSuppression::REASON_PROVIDER,
        );

        $historicalEmail = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'old@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
        );

        $released = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'current@example.com',
            reason: MessageSuppression::REASON_REPEATED_FAILURE,
            releasedAt: now(),
        );

        $ids = app(MessageDeliveryIssueReviewService::class)
            ->query()
            ->pluck('id')
            ->all();

        $this->assertContains($currentEmail->id, $ids);
        $this->assertContains($currentSms->id, $ids);
        $this->assertNotContains($historicalEmail->id, $ids);
        $this->assertNotContains($released->id, $ids);

        $contact->update(['email' => 'corrected@example.com']);

        $ids = app(MessageDeliveryIssueReviewService::class)
            ->query()
            ->pluck('id')
            ->all();

        $this->assertNotContains($currentEmail->id, $ids);
        $this->assertContains($currentSms->id, $ids);

        $this->assertDatabaseHas('message_suppressions', [
            'id' => $currentEmail->id,
            'destination' => 'current@example.com',
            'released_at' => null,
        ]);
    }

    public function test_contact_panel_flags_only_suppressions_matching_current_contact_information(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'panel@example.com',
        ]);

        $suppression = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'panel@example.com',
            reason: MessageSuppression::REASON_INVALID_DESTINATION,
        );

        $panel = app(ContactPanelRegistry::class)
            ->panelsFor($contact)
            ->first(
                fn ($candidate): bool => $candidate->key === 'messaging-delivery-issues',
            );

        $this->assertNotNull($panel);
        $this->assertSame('messaging', $panel->module);
        $this->assertTrue(
            $panel->data['deliveryIssues']
                ->contains(
                    fn (array $issue): bool =>
                        $issue['suppression']->is($suppression),
                ),
        );

        $contact->update(['email' => 'fixed@example.com']);

        $this->assertFalse(
            app(ContactPanelRegistry::class)
                ->panelsFor($contact->refresh())
                ->contains(
                    fn ($candidate): bool =>
                        $candidate->key === 'messaging-delivery-issues',
                ),
        );

        $this->assertDatabaseHas('message_suppressions', [
            'id' => $suppression->id,
            'released_at' => null,
        ]);
    }

    public function test_admin_can_release_reviewable_current_suppression_with_audit_metadata(): void
    {
        $user = User::factory()->create();
        Contact::factory()->create([
            'email' => 'verified@example.com',
        ]);

        $suppression = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'verified@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this
            ->actingAs($user)
            ->post(
                route('crm.messaging.delivery-issues.release', $suppression),
                [
                    'resolution_reason' => 'destination_verified',
                ],
            )
            ->assertRedirect(route('crm.messaging.delivery-issues.index'));

        $suppression->refresh();

        $this->assertNotNull($suppression->released_at);
        $this->assertSame(
            MessageSuppression::PROVIDER_RESEND,
            data_get($suppression->meta, 'release.provider'),
        );
        $this->assertSame(
            'crm_delivery_issue_review',
            data_get($suppression->meta, 'release.meta.source'),
        );
        $this->assertSame(
            $user->getKey(),
            data_get($suppression->meta, 'release.meta.actor_user_id'),
        );
        $this->assertSame(
            'destination_verified',
            data_get($suppression->meta, 'release.meta.resolution_reason'),
        );
        $this->assertSame(
            $suppression->getKey(),
            data_get($suppression->meta, 'release.meta.message_suppression_id'),
        );
    }

    public function test_complaint_suppression_cannot_be_released_from_admin_review_surface(): void
    {
        $user = User::factory()->create();
        Contact::factory()->create([
            'email' => 'complaint@example.com',
        ]);

        $suppression = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'complaint@example.com',
            reason: MessageSuppression::REASON_COMPLAINT,
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this
            ->actingAs($user)
            ->from(route('crm.messaging.delivery-issues.index'))
            ->post(
                route('crm.messaging.delivery-issues.release', $suppression),
                [
                    'resolution_reason' => 'destination_verified',
                ],
            )
            ->assertRedirect(route('crm.messaging.delivery-issues.index'))
            ->assertSessionHasErrors('resolution_reason');

        $this->assertNull($suppression->refresh()->released_at);
    }

    public function test_delivery_issue_dashboard_provider_reports_current_review_count_and_contact_link(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Delivery Review Contact',
            'email' => 'dashboard-bounce@example.com',
        ]);

        $suppression = $this->suppression(
            channel: MessageChannel::Email->value,
            destination: 'dashboard-bounce@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
        );

        $panel = app(MessagingDeliveryIssuesDashboardPanelProvider::class)
            ->panel(Request::create('/'));

        $this->assertNotNull($panel);
        $this->assertSame('messaging.delivery_issues', $panel['key']);
        $this->assertSame(1, $panel['count']);
        $this->assertSame(1, $panel['attention_count']);
        $this->assertCount(1, $panel['items']);
        $this->assertSame((string) $suppression->id, $panel['items'][0]['key']);
        $this->assertSame(
            route('crm.contacts.show', $contact),
            $panel['items'][0]['href'],
        );
        $this->assertSame(
            route('crm.messaging.delivery-issues.index'),
            $panel['primary_action']['href'],
        );
    }

    public function test_delivery_issue_review_route_is_protected_by_messaging_module_middleware(): void
    {
        $user = User::factory()->create();

        config()->set(
            'modules.enabled',
            array_values(array_filter(
                config('modules.enabled', []),
                fn (mixed $module): bool => $module !== 'messaging',
            )),
        );

        $this
            ->actingAs($user)
            ->get(route('crm.messaging.delivery-issues.index'))
            ->assertNotFound();
    }

    private function suppression(
        string $channel,
        string $destination,
        string $reason,
        ?string $provider = null,
        mixed $releasedAt = null,
    ): MessageSuppression {
        return MessageSuppression::query()->create([
            'channel' => $channel,
            'destination' => $destination,
            'reason' => $reason,
            'provider' => $provider,
            'source_event_id' => null,
            'suppressed_at' => now(),
            'released_at' => $releasedAt,
            'meta' => null,
        ]);
    }
}