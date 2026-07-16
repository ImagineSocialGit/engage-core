<?php

namespace Tests\Feature\Messaging;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Events\MessageConsentGranted;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GrantMessageConsentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_new_active_consent_and_returns_the_transition(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();

        $result = app(GrantMessageConsentAction::class)->handle(
            contact: $contact,
            data: [
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'source' => 'test',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'consented_at' => now(),
            ],
        );

        $this->assertInstanceOf(MessageConsentGrantResult::class, $result);
        $this->assertTrue($result->created);
        $this->assertFalse($result->wasActive);
        $this->assertTrue($result->isActive);
        $this->assertTrue($result->becameActive);
        $this->assertSame('webinar_nurture', $result->requestedScope);
        $this->assertSame('webinar', $result->domain);

        $this->assertDatabaseHas('message_consents', [
            'id' => $result->consent->getKey(),
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'source' => 'test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        Event::assertDispatched(
            MessageConsentGranted::class,
            fn (MessageConsentGranted $event): bool => $event->messageConsent->is($result->consent)
                && $event->scope === 'webinar'
                && ($event->data['requested_scope'] ?? null) === 'webinar_nurture',
        );
    }

    public function test_repeating_an_active_grant_reuses_history_without_overwriting_it(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();
        $originalAt = now()->subDay();

        $first = app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'source' => 'first_source',
            'consented_at' => $originalAt,
        ]);

        $second = app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source' => 'second_source',
            'consented_at' => now(),
        ]);

        $this->assertSame($first->consent->getKey(), $second->consent->getKey());
        $this->assertFalse($second->created);
        $this->assertTrue($second->wasActive);
        $this->assertTrue($second->isActive);
        $this->assertFalse($second->becameActive);
        $this->assertSame('webinar_nurture', $second->requestedScope);

        $this->assertDatabaseCount('message_consents', 1);
        $this->assertDatabaseHas('message_consents', [
            'id' => $first->consent->getKey(),
            'source' => 'first_source',
        ]);
        $this->assertDatabaseMissing('message_consents', [
            'source' => 'second_source',
        ]);

        Event::assertDispatchedTimes(MessageConsentGranted::class, 1);
    }

    public function test_reconsent_after_revocation_appends_a_new_grant(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();

        $first = app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source' => 'first_grant',
            'consented_at' => now()->subDays(2),
        ]);

        $revocation = ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'message_consent_id' => $first->consent->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_STOP,
            'revoked_at' => now()->subDay(),
            'source' => 'inbound_stop',
        ]);

        $second = app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source' => 'second_grant',
            'consented_at' => now(),
        ]);

        $this->assertNotSame($first->consent->getKey(), $second->consent->getKey());
        $this->assertTrue($second->created);
        $this->assertTrue($second->becameActive);
        $this->assertDatabaseCount('message_consents', 2);
        $this->assertDatabaseHas('message_consents', [
            'id' => $first->consent->getKey(),
            'source' => 'first_grant',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'id' => $second->consent->getKey(),
            'source' => 'second_grant',
        ]);
        $this->assertSame($first->consent->getKey(), $revocation->message_consent_id);

        Event::assertDispatchedTimes(MessageConsentGranted::class, 2);
    }

    public function test_batch_grant_returns_each_independent_transition_in_order(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();

        $results = app(GrantMessageConsentsAction::class)->handle($contact, [
            [
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'source' => 'batch_test',
            ],
            [
                'channel' => 'sms',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'source' => 'batch_test',
            ],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('email', $results[0]->channel);
        $this->assertSame('transactional', $results[0]->purpose);
        $this->assertSame('sms', $results[1]->channel);
        $this->assertSame('marketing', $results[1]->purpose);
        $this->assertTrue($results[0]->becameActive);
        $this->assertTrue($results[1]->becameActive);
        $this->assertDatabaseCount('message_consents', 2);

        Event::assertDispatchedTimes(MessageConsentGranted::class, 2);
    }

    public function test_granting_marketing_consent_does_not_mutate_campaign_enrollments(): void
    {
        $contact = Contact::factory()->create();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_PAUSED,
            'current_step' => 2,
            'started_at' => now()->subDays(2),
            'paused_at' => now()->subDay(),
            'resumed_at' => null,
        ]);

        $pausedAt = $enrollment->paused_at;

        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'source' => 'test',
        ]);

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_PAUSED, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertTrue($enrollment->paused_at->equalTo($pausedAt));
        $this->assertNull($enrollment->resumed_at);
    }
}
