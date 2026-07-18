<?php

namespace Tests\Feature\Observability;

use Monolog\Formatter\JsonFormatter;
use Tests\TestCase;

class ObservabilityConfigurationContractTest extends TestCase
{
    public function test_structured_daily_log_channel_is_available(): void
    {
        $channel = config('logging.channels.daily_json');

        $this->assertIsArray($channel);
        $this->assertSame('daily', $channel['driver']);
        $this->assertSame(JsonFormatter::class, $channel['formatter']);
        $this->assertSame(14, (int) $channel['days']);
    }

    public function test_request_correlation_is_registered_before_web_middleware(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString('RequestCorrelation::class', $bootstrap);
        $this->assertStringContainsString('prepend:', $bootstrap);
    }
}
