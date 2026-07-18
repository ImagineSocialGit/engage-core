<?php

namespace Tests\Feature\Webinars;

use App\Support\TokenContracts\TokenContractRegistry;
use Tests\TestCase;

class WebinarTokenReferenceContractTest extends TestCase
{
    public function test_legacy_reference_does_not_advertise_nonexistent_webinar_status(): void
    {
        $reference = require base_path('config/reference/tokens.php');

        $this->assertNotContains(
            'status',
            data_get($reference, 'models.webinar.available_fields', []),
        );
        $this->assertNotContains(
            '{webinar.status}',
            data_get($reference, 'models.webinar.tokens', []),
        );

        $this->assertContains(
            'status',
            data_get($reference, 'models.webinar_series.available_fields', []),
        );
        $this->assertContains(
            '{webinar_series.status}',
            data_get($reference, 'models.webinar_series.tokens', []),
        );
        $this->assertContains(
            'status',
            data_get($reference, 'models.webinar_registration.available_fields', []),
        );
        $this->assertContains(
            '{webinar_registration.status}',
            data_get($reference, 'models.webinar_registration.tokens', []),
        );
    }

    public function test_executable_registry_keeps_webinar_and_registration_status_distinct(): void
    {
        $tokens = app(TokenContractRegistry::class)->allAuthorableTokens();

        $this->assertNotContains('webinar.status', $tokens);
        $this->assertContains('webinar_series.status', $tokens);
        $this->assertContains('webinar_registration.status', $tokens);
    }
}
