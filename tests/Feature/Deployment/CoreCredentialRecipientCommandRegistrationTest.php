<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreCredentialRecipientCommandRegistrationTest extends TestCase
{
    public function test_read_only_core_recipient_inspection_is_registered(): void
    {
        $command = Artisan::all()['engage:credential-recipient:inspect'] ?? null;

        $this->assertNotNull($command);
        $this->assertInstanceOf(
            \App\Console\Commands\InspectCoreCredentialRecipientCommand::class,
            $command,
        );
    }
}