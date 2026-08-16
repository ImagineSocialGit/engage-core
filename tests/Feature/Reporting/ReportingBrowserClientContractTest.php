<?php

namespace Tests\Feature\Reporting;

use Tests\TestCase;

class ReportingBrowserClientContractTest extends TestCase
{
    public function test_browser_client_is_generic_passive_host_scoped_and_fail_open(): void
    {
        $client = file_get_contents(resource_path('js/reporting/client.js'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($client);
        $this->assertIsString($app);

        $this->assertStringContainsString("const DEFAULT_ENDPOINT = '/_reporting/observations'", $client);
        $this->assertStringContainsString('window.sessionStorage', $client);
        $this->assertStringNotContainsString('localStorage', $client);
        $this->assertStringNotContainsString('document.cookie', $client);
        $this->assertStringNotContainsString('navigator.userAgent', $client);
        $this->assertStringContainsString('new URL(document.referrer).hostname', $client);
        $this->assertStringContainsString("credentials: 'same-origin'", $client);
        $this->assertStringContainsString("status: 'unavailable'", $client);
        $this->assertStringContainsString("status: response.ok ? 'accepted' : 'rejected'", $client);
        $this->assertStringNotContainsString('traffic_class', $client);
        $this->assertStringNotContainsString('browser_family', $client);
        $this->assertStringNotContainsString('os_family', $client);

        $this->assertStringContainsString("import { createReportingClient } from './reporting/client'", $app);
        $this->assertStringContainsString('window.EngageReporting = createReportingClient()', $app);
        $this->assertStringNotContainsString('EngageReporting.record(', $app);
    }
}