<?php

namespace App\Console\Commands;

use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Console\Command;

class ValidateSetupCommand extends Command
{
    protected $signature = 'setup:validate';

    protected $description = 'Validate selected setup, module dependencies, registries, and DB-owned runtime readiness without mutating state.';

    public function handle(SetupValidationManager $manager): int
    {
        $result = $manager->validate();

        if ($result->findings() === []) {
            $this->info('Setup validation passed with no findings.');

            return self::SUCCESS;
        }

        foreach ($result->findings() as $finding) {
            $this->line($this->formatFinding($finding));
        }

        $this->newLine();
        $this->line(sprintf(
            'Setup validation complete: %d error(s), %d warning(s).',
            $result->errorCount(),
            $result->warningCount(),
        ));

        return $result->hasErrors()
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function formatFinding(SetupValidationFinding $finding): string
    {
        $parts = [
            strtoupper($finding->severity),
            $finding->code,
            $finding->message,
        ];

        $location = array_filter([
            $finding->module,
            $finding->source,
            $finding->path,
        ], fn (?string $value): bool => is_string($value) && trim($value) !== '');

        if ($location !== []) {
            $parts[] = '['.implode(' | ', $location).']';
        }

        $diagnostic = $this->compactDiagnostic($finding);

        if ($diagnostic !== null) {
            $parts[] = $diagnostic;
        }

        return implode(' ', $parts);
    }

    private function compactDiagnostic(SetupValidationFinding $finding): ?string
    {
        $data = array_filter([
            'context' => $finding->context,
            'meta' => $finding->meta,
        ], fn (array $value): bool => $value !== []);

        if ($data === []) {
            return null;
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if (! is_string($json) || $json === '') {
            return null;
        }

        return mb_strlen($json) > 500
            ? mb_substr($json, 0, 497).'...'
            : $json;
    }
}
