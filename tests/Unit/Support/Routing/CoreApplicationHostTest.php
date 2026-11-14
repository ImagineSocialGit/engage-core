<?php

namespace Tests\Unit\Support\Routing;

use App\Support\Routing\CoreApplicationHost;
use PHPUnit\Framework\TestCase;

final class CoreApplicationHostTest extends TestCase
{
    public function test_missing_crm_url_defaults_to_app_subdomain(): void
    {
        self::assertSame('app.example.org', CoreApplicationHost::resolve(null, 'example.org'));
        self::assertSame('app.example.org', CoreApplicationHost::resolve('', 'example.org'));
    }

    public function test_explicit_crm_url_preserves_existing_client_host(): void
    {
        self::assertSame(
            'crm.existing.example.org',
            CoreApplicationHost::resolve('https://crm.existing.example.org', 'example.org'),
        );
    }

    public function test_explicit_custom_app_url_is_accepted(): void
    {
        self::assertSame(
            'core.client.example.org',
            CoreApplicationHost::resolve('https://core.client.example.org', 'example.org'),
        );
    }
}