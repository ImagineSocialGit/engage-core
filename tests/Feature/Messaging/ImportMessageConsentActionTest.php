<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Events\MessageConsentGranted;
use App\Modules\Messaging\Models\ConsentRevocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ImportMessageConsentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_silent_and_reuses_an_existing_active_grant(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();
        $action = app(ImportMessageConsentAction::class);

        $first = $action->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_waitlist',
            consentedAt: '2026-07-01 12:00:00',
            source: 'first_import',
        );

        $second = $action->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            consentedAt: '2026-07-02 12:00:00',
            source: 'rerun_import',
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['consent']->getKey(), $second['consent']->getKey());
        $this->assertDatabaseCount('message_consents', 1);
        $this->assertDatabaseHas('message_consents', [
            'id' => $first['consent']->getKey(),
            'scope' => 'webinar',
            'source' => 'first_import',
        ]);

        Event::assertNotDispatched(MessageConsentGranted::class);
    }

    public function test_import_after_revocation_appends_a_new_grant_without_emitting_an_event(): void
    {
        Event::fake([MessageConsentGranted::class]);

        $contact = Contact::factory()->create();
        $action = app(ImportMessageConsentAction::class);

        $first = $action->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar',
            consentedAt: '2026-07-01 12:00:00',
            source: 'historical_import',
        );

        ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'message_consent_id' => $first['consent']->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'revoked_at' => '2026-07-05 12:00:00',
            'source' => 'historical_import',
        ]);

        $second = $action->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            consentedAt: '2026-07-10 12:00:00',
            source: 'later_import',
        );

        $this->assertTrue($second['created']);
        $this->assertNotSame($first['consent']->getKey(), $second['consent']->getKey());
        $this->assertDatabaseCount('message_consents', 2);

        Event::assertNotDispatched(MessageConsentGranted::class);
    }
}
