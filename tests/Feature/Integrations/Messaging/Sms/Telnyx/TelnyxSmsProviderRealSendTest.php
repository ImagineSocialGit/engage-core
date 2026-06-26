<?php

namespace Tests\Feature\Integrations\Messaging\Sms\Telnyx;

use App\Integrations\Messaging\Sms\Telnyx\TelnyxSmsProvider;
use Tests\TestCase;

class TelnyxSmsProviderRealSendTest extends TestCase
{
    public function test_it_sends_a_real_telnyx_sms(): void
    {
        if (! env('TELNYX_REAL_SEND_TEST')) {
            $this->markTestSkipped('Set TELNYX_REAL_SEND_TEST=true to run this test.');
        }

        $to = env('TELNYX_REAL_SEND_TO');

        if (! is_string($to) || trim($to) === '') {
            $this->markTestSkipped('Set TELNYX_REAL_SEND_TO to a real destination number.');
        }

        app(TelnyxSmsProvider::class)->send(
            to: $to,
            message: 'Engage Core Telnyx real send test.',
            meta: [
                'kind' => 'real_send_test',
            ],
        );

        $this->assertTrue(true);
    }
}