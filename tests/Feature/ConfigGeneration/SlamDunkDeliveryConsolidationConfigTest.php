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

    public function test_slam_dunk_consolidates_transactional_acknowledgements_into_selected_primary_templates(): void
    {
        $policy = config('messaging.delivery_consolidation.policies.webinar_registration');

        $this->assertTrue((bool) data_get($policy, 'enabled'));

        $this->assertSame([
            'consent.transactional.email.acknowledgement',
        ], data_get($policy, 'groups.initial_email.member_intents'));
        $this->assertFalse((bool) data_get($policy, 'groups.initial_email.include_marketing_unsubscribe'));
        $this->assertSame([
            'payload_key' => 'body',
            'position' => 'append',
            'separator' => "\n\n",
        ], data_get($policy, 'groups.initial_email.placement'));
        $this->assertNull(data_get(
            $policy,
            'groups.initial_email.fragments.delivery_consolidation_marketing_email_acknowledgement',
        ));
        $this->assertNull(data_get($policy, 'groups.initial_email.template'));

        $this->assertSame([
            'consent.transactional.sms.acknowledgement',
        ], data_get($policy, 'groups.initial_sms.member_intents'));
        $this->assertSame([
            'payload_key' => 'message',
            'position' => 'append',
            'separator' => ' ',
        ], data_get($policy, 'groups.initial_sms.placement'));
        $this->assertNull(data_get($policy, 'groups.initial_sms.template'));
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
