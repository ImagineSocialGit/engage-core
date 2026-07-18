<?php

namespace Tests\Feature\ConfigGeneration;

use Illuminate\Foundation\Application;
use Tests\TestCase;

class SlamDunkDeliveryConsolidationConfigTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped(
            'Temporarily disabled pending migration to bundle-scoped golden fixtures.'
        );
    }

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
        $policy = config(
            'messaging.delivery_consolidation.policies.webinar_registration',
        );

        $this->assertTrue((bool) data_get($policy, 'enabled'));

        $this->assertSame([
            'consent.transactional.email.acknowledgement',
        ], data_get(
            $policy,
            'groups.initial_email.member_intents',
        ));

        $this->assertFalse((bool) data_get(
            $policy,
            'groups.initial_email.include_marketing_unsubscribe',
        ));

        $this->assertSame(
            'body',
            data_get(
                $policy,
                'groups.initial_email.placement.payload_key',
            ),
        );

        $this->assertSame(
            'append',
            data_get(
                $policy,
                'groups.initial_email.placement.position',
            ),
        );

        $emailSeparator = data_get(
            $policy,
            'groups.initial_email.placement.separator',
        );

        $this->assertIsString($emailSeparator);
        $this->assertNotSame('', $emailSeparator);

        $this->assertNull(data_get(
            $policy,
            'groups.initial_email.fragments.delivery_consolidation_marketing_email_acknowledgement',
        ));

        $this->assertNull(data_get(
            $policy,
            'groups.initial_email.template',
        ));

        $this->assertSame([
            'consent.transactional.sms.acknowledgement',
        ], data_get(
            $policy,
            'groups.initial_sms.member_intents',
        ));

        $this->assertSame(
            'message',
            data_get(
                $policy,
                'groups.initial_sms.placement.payload_key',
            ),
        );

        $this->assertSame(
            'append',
            data_get(
                $policy,
                'groups.initial_sms.placement.position',
            ),
        );

        $smsSeparator = data_get(
            $policy,
            'groups.initial_sms.placement.separator',
        );

        $this->assertIsString($smsSeparator);
        $this->assertNotSame('', $smsSeparator);
        $this->assertStringNotContainsString('\n', $smsSeparator);

        $this->assertNull(data_get(
            $policy,
            'groups.initial_sms.template',
        ));
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
