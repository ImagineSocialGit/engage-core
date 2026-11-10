<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreEndpointOriginCommandRegistrationTest extends TestCase
{
    public function test_read_only_core_endpoint_origin_inspection_is_registered(): void
    {
        $command = Artisan::all()['engage:credential-endpoint:inspect'] ?? null;

        $this->assertNotNull($command);
        $this->assertInstanceOf(
            \App\Console\Commands\InspectCoreEndpointOriginCommand::class,
            $command,
        );
    }
}