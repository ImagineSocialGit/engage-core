<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreCredentialInstallationCommandRegistrationTest extends TestCase
{
    public function test_read_only_installation_target_inspection_is_registered(): void
    {
        $command = Artisan::all()['engage:credential-installation:inspect'] ?? null;

        $this->assertNotNull($command);
        $this->assertInstanceOf(
            \App\Console\Commands\InspectCoreCredentialInstallationCommand::class,
            $command,
        );
    }
}