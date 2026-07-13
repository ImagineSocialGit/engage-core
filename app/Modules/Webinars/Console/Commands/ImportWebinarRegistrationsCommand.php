<?php

namespace App\Modules\Webinars\Console\Commands;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Actions\ImportWebinarRegistrationAction;
use App\Modules\Webinars\Data\WebinarRegistrationImportRow;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class ImportWebinarRegistrationsCommand extends Command
{
    protected $signature = 'webinars:import-registrations
        {path : Path to the webinar registration CSV}
        {--webinar= : Webinar slug}
        {--apply : Persist the import and schedule future-valid reminders}';

    protected $description = 'Dry-run or apply a webinar registration import from CSV.';

    public function __construct(
        private readonly WebinarRegistrationCsvReader $csvReader,
        private readonly ImportWebinarRegistrationAction $importAction,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $webinarSlug = trim((string) $this->option('webinar'));

        if ($webinarSlug === '') {
            $this->error('The --webinar option is required.');

            return self::FAILURE;
        }

        $webinar = Webinar::query()
            ->where('slug', $webinarSlug)
            ->first();

        if (! $webinar) {
            $this->error("Webinar [{$webinarSlug}] was not found.");

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->argument('path'));

        try {
            $rows = iterator_to_array($this->csvReader->read($path), preserve_keys: true);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('The CSV contains no importable registration rows.');

            return self::SUCCESS;
        }

        $duplicateEmails = collect($rows)
            ->map(fn (WebinarRegistrationImportRow $row): string => $row->email)
            ->duplicates()
            ->unique()
            ->values()
            ->all();

        if ($duplicateEmails !== []) {
            $this->error('The CSV contains duplicate email addresses: '.implode(', ', $duplicateEmails));

            return self::FAILURE;
        }

        $preparedRows = [];

        foreach ($rows as $rowNumber => $row) {
            $preparedRows[$rowNumber] = $this->prepareRow(
                row: $row,
                rowNumber: (int) $rowNumber,
            );
        }

        if (! $this->option('apply')) {
            return $this->renderDryRun(
                webinar: $webinar,
                rows: $preparedRows,
            );
        }

        return $this->applyImport(
            webinar: $webinar,
            rows: $preparedRows,
            importedAt: now(),
        );
    }

    /**
     * @param array<int, WebinarRegistrationImportRow> $rows
     */
    private function renderDryRun(Webinar $webinar, array $rows): int
    {
        $tableRows = [];

        foreach ($rows as $rowNumber => $row) {
            $contact = Contact::query()
                ->where('email', $row->email)
                ->first();

            $registrationExists = $contact !== null
                && WebinarRegistration::query()
                    ->where('webinar_id', $webinar->getKey())
                    ->where('contact_id', $contact->getKey())
                    ->exists();

            $tableRows[] = [
                $rowNumber,
                $row->email,
                $contact ? 'existing' : 'new',
                $registrationExists ? 'existing' : 'new',
                implode(', ', $row->acceptedTransactionalChannels()) ?: 'none',
                implode(', ', $row->acceptedMarketingChannels()) ?: 'none',
            ];
        }

        $this->info("Dry run for webinar [{$webinar->slug}] — {$webinar->starts_at?->toDateTimeString()}");
        $this->table(
            ['CSV row', 'Email', 'Contact', 'Registration', 'Transactional', 'Marketing'],
            $tableRows,
        );
        $this->newLine();
        $this->comment('No changes were written. Re-run with --apply to import these registrations.');

        return self::SUCCESS;
    }

    /**
     * @param array<int, WebinarRegistrationImportRow> $rows
     */
    private function applyImport(
        Webinar $webinar,
        array $rows,
        Carbon $importedAt,
    ): int {
        $totals = [
            'processed' => 0,
            'failed' => 0,
            'contacts_created' => 0,
            'registrations_created' => 0,
            'consents_created' => 0,
            'consents_updated' => 0,
            'reminders_scheduled' => 0,
        ];

        foreach ($rows as $rowNumber => $row) {
            try {
                $result = $this->importAction->handle(
                    webinar: $webinar,
                    row: $row,
                    registeredAt: $importedAt,
                    scheduleReminders: true,
                );

                $totals['processed']++;
                $totals['contacts_created'] += $result->contactCreated ? 1 : 0;
                $totals['registrations_created'] += $result->registrationCreated ? 1 : 0;
                $totals['consents_created'] += $result->consentsCreated;
                $totals['consents_updated'] += $result->consentsUpdated;
                $totals['reminders_scheduled'] += $result->remindersScheduled;

                $this->line(sprintf(
                    'Row %d: %s — imported (%d reminder%s scheduled)',
                    $rowNumber,
                    $row->email,
                    $result->remindersScheduled,
                    $result->remindersScheduled === 1 ? '' : 's',
                ));
            } catch (Throwable $exception) {
                $totals['failed']++;
                $this->error("Row {$rowNumber}: {$row->email} — {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $totals['processed']],
                ['Failed', $totals['failed']],
                ['Contacts created', $totals['contacts_created']],
                ['Registrations created', $totals['registrations_created']],
                ['Consents created', $totals['consents_created']],
                ['Consents updated', $totals['consents_updated']],
                ['Reminders scheduled', $totals['reminders_scheduled']],
            ],
        );

        return $totals['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function prepareRow(
        WebinarRegistrationImportRow $row,
        int $rowNumber,
    ): WebinarRegistrationImportRow {
        $normalizedPhone = $this->phoneNumberNormalizer->normalize($row->phone);

        if ($normalizedPhone !== null) {
            return new WebinarRegistrationImportRow(
                email: $row->email,
                firstName: $row->firstName,
                lastName: $row->lastName,
                phone: $normalizedPhone,
                transactionalEmailConsent: $row->transactionalEmailConsent,
                transactionalSmsConsent: $row->transactionalSmsConsent,
                marketingEmailConsent: $row->marketingEmailConsent,
                marketingSmsConsent: $row->marketingSmsConsent,
            );
        }

        if ($row->phone !== null || $row->transactionalSmsConsent || $row->marketingSmsConsent) {
            $this->warn(
                "Row {$rowNumber}: {$row->email} has no valid normalized phone number; "
                .'phone will be imported as null and SMS consents will be disabled.'
            );
        }

        return new WebinarRegistrationImportRow(
            email: $row->email,
            firstName: $row->firstName,
            lastName: $row->lastName,
            phone: null,
            transactionalEmailConsent: $row->transactionalEmailConsent,
            transactionalSmsConsent: false,
            marketingEmailConsent: $row->marketingEmailConsent,
            marketingSmsConsent: false,
        );
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return base_path($path);
    }
}
