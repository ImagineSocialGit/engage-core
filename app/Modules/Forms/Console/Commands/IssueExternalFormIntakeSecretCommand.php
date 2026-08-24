<?php

namespace App\Modules\Forms\Console\Commands;

use App\Modules\Forms\Services\ExternalFormIntakeClientResolver;
use App\Modules\Forms\Services\ExternalFormIntakeSecretPolicy;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class IssueExternalFormIntakeSecretCommand extends Command
{
    protected $signature = 'forms:external-intake:issue-secret
        {client? : External Forms intake client ID; optional when exactly one client is configured}';

    protected $description = 'Issue a shared signing secret for a configured external Forms intake client.';

    public function handle(
        ExternalFormIntakeClientResolver $clients,
        ExternalFormIntakeSecretPolicy $secrets,
    ): int {
        try {
            $clientId = $this->resolveClientId(
                $clients->configuredClientIds(),
            );
            $secret = $secrets->generate();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->warn('Generated secret was not written or stored. Copy it now.');
        $this->newLine();
        $this->line('Engage Core (.env)');
        $this->line("FORMS_EXTERNAL_INTAKE_CLIENT_ID={$clientId}");
        $this->line("FORMS_EXTERNAL_INTAKE_CLIENT_SECRET={$secret}");
        $this->newLine();
        $this->line('Engage Artist Sites (.env)');
        $this->line("ENGAGE_CORE_CLIENT_KEY={$clientId}");
        $this->line("ENGAGE_CORE_SIGNING_SECRET={$secret}");
        $this->newLine();
        $this->comment('No environment file, database row, or application configuration was changed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $configuredClientIds
     */
    private function resolveClientId(array $configuredClientIds): string
    {
        $argument = $this->argument('client');

        if (is_string($argument) && $argument !== '') {
            if (! in_array($argument, $configuredClientIds, true)) {
                throw new InvalidArgumentException(
                    "External Forms intake client [{$argument}] is not configured.",
                );
            }

            return $argument;
        }

        if (count($configuredClientIds) === 1) {
            return $configuredClientIds[0];
        }

        throw new InvalidArgumentException(sprintf(
            'Specify a client ID. Configured external Forms intake clients: %s.',
            implode(', ', $configuredClientIds),
        ));
    }
}