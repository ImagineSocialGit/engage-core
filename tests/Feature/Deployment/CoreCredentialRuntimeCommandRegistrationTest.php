<?php

namespace Tests\Feature\Deployment;

use App\Console\Commands\InspectCoreCredentialRuntimeCommand;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreCredentialRuntimeCommandRegistrationTest extends TestCase
{
    public function test_runtime_probe_and_command_are_registered_without_optional_packages(): void
    {
        self::assertInstanceOf(CoreCredentialRuntimeProbe::class, app(CoreCredentialRuntimeProbe::class));
        self::assertInstanceOf(InspectCoreCredentialRuntimeCommand::class,
            Artisan::all()['engage:credential-runtime:inspect'] ?? null);
    }
}