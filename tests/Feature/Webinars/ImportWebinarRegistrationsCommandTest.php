<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Console\Commands\ImportWebinarRegistrationsCommand;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportWebinarRegistrationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(ImportWebinarRegistrationsCommand::class),
        );
    }

    public function test_command_is_dry_run_by_default_and_writes_nothing(): void
    {
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
        ]);

        $path = $this->writeCsv([
            [
                'Jane',
                'Doe',
                'jane@example.com',
                '+15555550123',
                '1',
                '1',
                '1',
                '1',
            ],
        ]);

        $this->artisan('webinars:import-registrations', [
            'path' => $path,
            '--webinar' => $webinar->slug,
        ])
            ->expectsOutputToContain('Dry run for webinar')
            ->expectsOutputToContain('No changes were written.')
            ->assertSuccessful();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('message_consents', 0);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    public function test_apply_imports_contact_registration_and_consents(): void
    {
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
        ]);

        $path = $this->writeCsv([
            [
                'Jane',
                'Doe',
                'jane@example.com',
                '+15555550123',
                '1',
                '1',
                '1',
                '1',
            ],
        ]);

        $this->artisan('webinars:import-registrations', [
            'path' => $path,
            '--webinar' => $webinar->slug,
            '--apply' => true,
        ])->assertSuccessful();

        $contact = Contact::query()->sole();
        $registration = WebinarRegistration::query()->sole();

        $this->assertSame('jane@example.com', $contact->email);
        $this->assertSame($webinar->getKey(), $registration->webinar_id);
        $this->assertSame($contact->getKey(), $registration->contact_id);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
        ]);
    }

    public function test_command_rejects_duplicate_emails_before_writing_anything(): void
    {
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
        ]);

        $path = $this->writeCsv([
            ['Jane', 'Doe', 'jane@example.com', '', '1', '0', '0', '0'],
            ['Jane', 'Doe', 'JANE@example.com', '', '1', '0', '0', '0'],
        ]);

        $this->artisan('webinars:import-registrations', [
            'path' => $path,
            '--webinar' => $webinar->slug,
            '--apply' => true,
        ])
            ->expectsOutputToContain('duplicate email addresses')
            ->assertFailed();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = storage_path('framework/testing/webinar-registration-import-'.uniqid().'.csv');

        $handle = fopen($path, 'w');

        fputcsv($handle, [
            'first_name',
            'last_name',
            'email',
            'phone',
            'transactional_email_consent',
            'transactional_sms_consent',
            'marketing_email_consent',
            'marketing_sms_consent',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
