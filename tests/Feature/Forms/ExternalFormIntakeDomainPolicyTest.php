<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Services\ExternalFormIntakeDomainPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalFormIntakeDomainPolicyTest extends TestCase
{
    public function test_it_normalizes_bare_domains_and_preserves_declared_order(): void
    {
        $this->assertSame(
            ['example.com', 'forms.example.test'],
            app(ExternalFormIntakeDomainPolicy::class)->normalize(
                [' Example.COM. ', 'forms.example.test'],
                'engage_sites',
            ),
        );
    }

    #[DataProvider('invalidDomains')]
    public function test_it_rejects_values_that_are_not_bare_public_domains(mixed $domain): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ExternalFormIntakeDomainPolicy::class)->normalize(
            [$domain],
            'engage_sites',
        );
    }

    public static function invalidDomains(): array
    {
        return [
            'scheme' => ['https://example.com'],
            'path' => ['example.com/forms'],
            'port' => ['example.com:443'],
            'wildcard' => ['*.example.com'],
            'IP address' => ['203.0.113.10'],
            'single label' => ['localhost'],
            'non-string' => [123],
        ];
    }

    public function test_it_rejects_duplicates_after_canonicalization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ExternalFormIntakeDomainPolicy::class)->normalize(
            ['example.com', 'EXAMPLE.COM.'],
            'engage_sites',
        );
    }
}