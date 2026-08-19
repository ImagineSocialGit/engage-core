<?php

namespace App\Support\TestingTools;

use RuntimeException;

class TestingToolGuard
{
    /**
     * Testing-tool routes may exist only in local development and the PHPUnit
     * testing environment. The testing allowance exists solely so the safety
     * contract itself can be exercised by automated tests.
     */
    public function routesMayRegister(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function assertAvailable(): void
    {
        abort_unless($this->routesMayRegister(), 404);
    }

    /**
     * Real provider execution is never permitted from a testing tool.
     * Messaging's local runtime is the only allowed delivery path because it
     * routes email/SMS through DevMessageSink instead of external providers.
     */
    public function assertDevSinkDelivery(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'Testing-tool message delivery is available only in the local environment, where Messaging uses DevMessageSink.',
            );
        }
    }
}