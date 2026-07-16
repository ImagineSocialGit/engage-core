<?php

namespace Tests\Feature\ConfigGeneration;

use Illuminate\Foundation\Application;
use Tests\TestCase;

class SlamDunkDeliveryConsolidationConfigTest extends TestCase
{
    private string|false $originalClientKey;

    public function createApplication(): Application
    {
        $this->originalClientKey = getenv('CLIENT_KEY');

        putenv('CLIENT_KEY=slam-dunk-crm');
        $_ENV['CLIENT_KEY'] = 'slam-dunk-crm';
        $_SERVER['CLIENT_KEY'] = 'slam-dunk-crm';

        return parent::createApplication();
    }

    public function test_slam_dunk_enables_webinar_registration_delivery_consolidation(): void
    {
        $this->assertTrue(
            (bool) config('messaging.delivery_consolidation.policies.webinar_registration.enabled'),
        );
        $this->assertIsArray(
            config('messaging.delivery_consolidation.policies.webinar_registration.groups.initial_email.template'),
        );
        $this->assertIsArray(
            config('messaging.delivery_consolidation.policies.webinar_registration.groups.initial_sms.template'),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->originalClientKey === false) {
            putenv('CLIENT_KEY');
            unset($_ENV['CLIENT_KEY'], $_SERVER['CLIENT_KEY']);

            return;
        }

        putenv('CLIENT_KEY='.$this->originalClientKey);
        $_ENV['CLIENT_KEY'] = $this->originalClientKey;
        $_SERVER['CLIENT_KEY'] = $this->originalClientKey;
    }
}
