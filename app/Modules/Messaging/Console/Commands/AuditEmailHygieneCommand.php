<?php

namespace App\Modules\Messaging\Console\Commands;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Data\Email\EmailHygieneResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\Email\EmailHygieneService;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AuditEmailHygieneCommand extends Command
{
    protected $signature = 'messaging:email-hygiene
        {--batch= : Audit Contacts belonging to one Contact import batch ID}
        {--all : Audit every Contact that currently has an email address}
        {--email=* : Audit one or more explicit email addresses}
        {--suppress-invalid : Persist definitively invalid addresses as Messaging suppressions}';

    protected $description = 'Audit email syntax, active suppression state, and domain mail routing before delivery.';

    public function handle(
        EmailHygieneService $hygiene,
        MessageSuppressionService $suppressions,
    ): int {
        $sourceCount = collect([
            $this->hasBatchOption(),
            (bool) $this->option('all'),
            $this->explicitEmails() !== [],
        ])->filter()->count();

        if ($sourceCount !== 1) {
            $this->error('Choose exactly one source: --batch=<id>, --all, or one or more --email=<address> options.');

            return self::FAILURE;
        }

        $emails = $this->emails();
        $seen = [];
        $counts = [
            EmailHygieneResult::STATUS_VALID => 0,
            EmailHygieneResult::STATUS_INVALID => 0,
            EmailHygieneResult::STATUS_SUPPRESSED => 0,
            EmailHygieneResult::STATUS_UNKNOWN => 0,
        ];
        $problems = [];
        $suppressedNow = 0;

        foreach ($emails as $rawEmail) {
            $identity = strtolower(trim((string) $rawEmail));

            if (array_key_exists($identity, $seen)) {
                continue;
            }

            $seen[$identity] = true;
            $result = $hygiene->inspect((string) $rawEmail);
            $counts[$result->status]++;

            if ($result->status !== EmailHygieneResult::STATUS_VALID && count($problems) < 25) {
                $problems[] = [
                    $result->email !== '' ? $result->email : '[blank]',
                    $result->status,
                    $result->reason,
                ];
            }

            if ((bool) $this->option('suppress-invalid')
                && $result->isInvalid()
                && $result->email !== ''
            ) {
                $wasSuppressed = $suppressions->isSuppressed(
                    MessageChannel::Email,
                    $result->email,
                );

                $suppressions->suppress(
                    channel: MessageChannel::Email,
                    destination: $result->email,
                    reason: MessageSuppression::REASON_INVALID_DESTINATION,
                );

                if (! $wasSuppressed) {
                    $suppressedNow++;
                }
            }
        }

        $this->table(
            ['Result', 'Count'],
            [
                ['Valid domain', $counts[EmailHygieneResult::STATUS_VALID]],
                ['Invalid', $counts[EmailHygieneResult::STATUS_INVALID]],
                ['Already suppressed', $counts[EmailHygieneResult::STATUS_SUPPRESSED]],
                ['Unknown / DNS unavailable', $counts[EmailHygieneResult::STATUS_UNKNOWN]],
                ['Unique addresses audited', count($seen)],
                ['New invalid-destination suppressions', $suppressedNow],
            ],
        );

        if ($problems !== []) {
            $this->newLine();
            $this->line('First '.count($problems).' non-valid results:');
            $this->table(['Email', 'Status', 'Reason'], $problems);
        }

        if ($counts[EmailHygieneResult::STATUS_UNKNOWN] > 0) {
            $this->newLine();
            $this->warn('Unknown results were not suppressed. DNS uncertainty is not proof that a mailbox or domain is invalid.');
        }

        return self::SUCCESS;
    }

    /** @return iterable<int, string> */
    private function emails(): iterable
    {
        if ($this->hasBatchOption()) {
            $batch = ContactImportBatch::query()->find((int) $this->option('batch'));

            if (! $batch) {
                throw new \InvalidArgumentException('The requested Contact import batch does not exist.');
            }

            return $this->emailsFromQuery($batch->importedContactsQuery());
        }

        if ((bool) $this->option('all')) {
            return $this->emailsFromQuery(Contact::query());
        }

        return $this->explicitEmails();
    }

    /** @return iterable<int, string> */
    private function emailsFromQuery(Builder $query): iterable
    {
        foreach ($query
            ->select('email')
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->orderBy('email')
            ->cursor() as $contact
        ) {
            yield (string) $contact->email;
        }
    }

    /** @return array<int, string> */
    private function explicitEmails(): array
    {
        $emails = $this->option('email');

        return is_array($emails)
            ? array_values(array_filter(
                array_map(static fn (mixed $email): string => trim((string) $email), $emails),
                static fn (string $email): bool => $email !== '',
            ))
            : [];
    }

    private function hasBatchOption(): bool
    {
        $batch = $this->option('batch');

        return is_scalar($batch) && trim((string) $batch) !== '';
    }
}