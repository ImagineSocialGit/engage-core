<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\MessageConsentGranted;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\ImportWebinarRegistrationAction;
use App\Modules\Webinars\Data\WebinarRegistrationImportRow;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationCsvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ImportWebinarRegistrationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_reader_normalizes_headers_and_parses_consent_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'webinar-import-');

        file_put_contents($path, implode("\n", [
            'First Name,Last Name,Email,Phone,Transactional Email Consent,Transactional SMS Consent,Marketing Email Consent,Marketing SMS Consent',
            'Jane,Doe,JANE@example.com,+15555550123,yes,1,no,false',
        ]));

        try {
            $rows = iterator_to_array(app(WebinarRegistrationCsvReader::class)->read($path));
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $rows);

        $row = array_values($rows)[0];

        $this->assertSame('jane@example.com', $row->email);
        $this->assertSame('Jane', $row->firstName);
        $this->assertSame('Doe', $row->lastName);
        $this->assertSame('+15555550123', $row->phone);
        $this->assertTrue($row->transactionalEmailConsent);
        $this->assertTrue($row->transactionalSmsConsent);
        $this->assertFalse($row->marketingEmailConsent);
        $this->assertFalse($row->marketingSmsConsent);
        $this->assertSame(['email', 'sms'], $row->acceptedTransactionalChannels());
        $this->assertSame([], $row->acceptedMarketingChannels());
    }

    public function test_it_imports_contact_consents_and_registration_without_messages_or_consent_events(): void
    {
        Event::fake([MessageConsentGranted::class]);
        Carbon::setTestNow('2026-07-13 17:00:00');

        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
            'starts_at' => now()->addHours(2),
        ]);

        $row = WebinarRegistrationImportRow::fromArray([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '(555) 555-0123',
            'transactional_email_consent' => '1',
            'transactional_sms_consent' => 'yes',
            'marketing_email_consent' => 'true',
            'marketing_sms_consent' => '0',
        ]);

        $result = app(ImportWebinarRegistrationAction::class)->handle(
            webinar: $webinar,
            row: $row,
            registeredAt: now(),
            scheduleReminders: false,
        );

        $this->assertTrue($result->contactCreated);
        $this->assertTrue($result->registrationCreated);
        $this->assertSame(3, $result->consentsCreated);
        $this->assertSame(0, $result->consentsUpdated);

        $contact = Contact::query()->where('email', 'jane@example.com')->firstOrFail();
        $registration = WebinarRegistration::query()->firstOrFail();

        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('+15555550123', $contact->phone);
        $this->assertSame('webinar', $contact->source);
        $this->assertSame($webinar->slug, $contact->subsource);

        $this->assertSame($contact->getKey(), $registration->contact_id);
        $this->assertSame($webinar->getKey(), $registration->webinar_id);
        $this->assertSame($webinar->slug, $registration->webinar_slug);
        $this->assertSame('pending', $registration->status);
        $this->assertSame('webinar_registration_import', $registration->source);
        $this->assertSame(
            ['email', 'sms'],
            data_get($registration->meta, 'accepted_channels.transactional'),
        );
        $this->assertSame(
            ['email'],
            data_get($registration->meta, 'accepted_channels.marketing'),
        );

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'source' => 'webinar_registration_import',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'source' => 'webinar_registration_import',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'source' => 'webinar_registration_import',
        ]);

        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseCount('scheduled_messages', 0);
        Event::assertNotDispatched(MessageConsentGranted::class);
    }

    public function test_rerun_reuses_contact_registration_and_consents(): void
    {
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
        ]);

        $row = WebinarRegistrationImportRow::fromArray([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '+15555550123',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => true,
        ]);

        $action = app(ImportWebinarRegistrationAction::class);

        $first = $action->handle($webinar, $row, '2026-07-13 17:00:00', scheduleReminders: false);
        $second = $action->handle($webinar, $row, '2026-07-13 17:05:00', scheduleReminders: false);

        $this->assertTrue($first->contactCreated);
        $this->assertTrue($first->registrationCreated);
        $this->assertSame(2, $first->consentsCreated);

        $this->assertFalse($second->contactCreated);
        $this->assertFalse($second->registrationCreated);
        $this->assertSame(0, $second->consentsCreated);
        $this->assertSame(2, $second->consentsUpdated);

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('webinar_registrations', 1);
        $this->assertDatabaseCount('message_consents', 2);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $registration = WebinarRegistration::query()->firstOrFail();

        $this->assertTrue(
            $registration->registered_at->equalTo(Carbon::parse('2026-07-13 17:00:00'))
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
