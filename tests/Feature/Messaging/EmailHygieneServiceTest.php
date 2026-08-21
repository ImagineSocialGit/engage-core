<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Data\Email\EmailHygieneResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\Email\EmailDomainHealthChecker;
use App\Modules\Messaging\Services\Email\EmailHygieneService;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailHygieneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_invalid_email_syntax_without_dns_lookup(): void
    {
        $checker = new FakeEmailDomainHealthChecker();
        $service = new EmailHygieneService($checker, app(MessageSuppressionService::class));

        $result = $service->inspect('not-an-email');

        $this->assertSame(EmailHygieneResult::STATUS_INVALID, $result->status);
        $this->assertSame('invalid_format', $result->reason);
        $this->assertSame([], $checker->lookups);
    }

    public function test_it_reports_existing_suppression_before_dns_lookup(): void
    {
        app(MessageSuppressionService::class)->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
        );

        $checker = new FakeEmailDomainHealthChecker();
        $service = new EmailHygieneService($checker, app(MessageSuppressionService::class));

        $result = $service->inspect('PERSON@EXAMPLE.COM');

        $this->assertSame(EmailHygieneResult::STATUS_SUPPRESSED, $result->status);
        $this->assertSame('active_suppression', $result->reason);
        $this->assertSame([], $checker->lookups);
    }

    public function test_it_distinguishes_valid_invalid_and_unknown_domain_results(): void
    {
        $checker = new FakeEmailDomainHealthChecker([
            'valid.test' => true,
            'invalid.test' => false,
            'unknown.test' => null,
        ]);
        $service = new EmailHygieneService($checker, app(MessageSuppressionService::class));

        $valid = $service->inspect('valid@valid.test');
        $invalid = $service->inspect('invalid@invalid.test');
        $unknown = $service->inspect('unknown@unknown.test');

        $this->assertSame(EmailHygieneResult::STATUS_VALID, $valid->status);
        $this->assertSame('mail_route_present', $valid->reason);
        $this->assertSame(EmailHygieneResult::STATUS_INVALID, $invalid->status);
        $this->assertSame('no_mail_route', $invalid->reason);
        $this->assertSame(EmailHygieneResult::STATUS_UNKNOWN, $unknown->status);
        $this->assertSame('dns_unavailable', $unknown->reason);
    }
}

class FakeEmailDomainHealthChecker extends EmailDomainHealthChecker
{
    /** @var array<int, string> */
    public array $lookups = [];

    /** @param array<string, bool|null> $results */
    public function __construct(private readonly array $results = []) {}

    public function hasMailRoute(string $domain): ?bool
    {
        $this->lookups[] = $domain;

        return array_key_exists($domain, $this->results)
            ? $this->results[$domain]
            : true;
    }
}