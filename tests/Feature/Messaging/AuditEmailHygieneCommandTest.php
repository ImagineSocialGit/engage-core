<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\Email\EmailDomainHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditEmailHygieneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_audit_uses_occurrence_membership_and_can_suppress_invalid_addresses(): void
    {
        $targetBatch = ContactImportBatch::factory()->create();
        $laterBatch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'not-an-email',
            'contact_import_batch_id' => $laterBatch->id,
        ]);

        ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $targetBatch->id,
            'contact_id' => $contact->id,
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => 'not-an-email',
            'row_fingerprint' => hash('sha256', 'not-an-email'),
            'meta' => [],
        ]);

        $this->app->instance(EmailDomainHealthChecker::class, new CommandFakeEmailDomainHealthChecker());

        $this->artisan('messaging:email-hygiene', [
            '--batch' => $targetBatch->id,
            '--suppress-invalid' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'not-an-email',
            'reason' => MessageSuppression::REASON_INVALID_DESTINATION,
            'released_at' => null,
        ]);
    }

    public function test_unknown_dns_result_is_not_suppressed(): void
    {
        Contact::factory()->create([
            'email' => 'person@unknown.test',
        ]);

        $this->app->instance(
            EmailDomainHealthChecker::class,
            new CommandFakeEmailDomainHealthChecker(['unknown.test' => null]),
        );

        $this->artisan('messaging:email-hygiene', [
            '--all' => true,
            '--suppress-invalid' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@unknown.test',
        ]);
    }

    public function test_command_requires_exactly_one_audit_source(): void
    {
        $this->artisan('messaging:email-hygiene')->assertExitCode(Command::FAILURE);

        $this->artisan('messaging:email-hygiene', [
            '--all' => true,
            '--email' => ['person@example.com'],
        ])->assertExitCode(Command::FAILURE);
    }
}

class CommandFakeEmailDomainHealthChecker extends EmailDomainHealthChecker
{
    /** @param array<string, bool|null> $results */
    public function __construct(private readonly array $results = []) {}

    public function hasMailRoute(string $domain): ?bool
    {
        return array_key_exists($domain, $this->results)
            ? $this->results[$domain]
            : true;
    }
}