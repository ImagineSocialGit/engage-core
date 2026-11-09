<?php

namespace App\Support\Deployment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use RuntimeException;

class EnvironmentFileRepository
{
    public function pathForScope(string $scope): string
    {
        return match ($scope) {
            EnvironmentVariableDefinition::SCOPE_ROOT => base_path('.env'),
            EnvironmentVariableDefinition::SCOPE_CLIENT => $this->clientEnvironmentPath(),
            default => throw new RuntimeException("Unsupported environment scope [{$scope}]."),
        };
    }

    /** @return array<string, string> */
    public function valuesForScope(string $scope): array
    {
        $path = $this->pathForScope($scope);

        if (! is_file($path)) {
            return [];
        }

        $directory = dirname($path);
        $filename = basename($path);

        return Dotenv::createArrayBacked($directory, $filename)->safeLoad();
    }

    /**
     * Examine persisted configuration without loading it into the process or
     * exposing any value. The existing dotenv loader handles normal syntax and
     * value interpretation. Duplicate names and incomplete quoted assignments
     * require separate physical inspection because a parsed key/value map
     * cannot reliably retain these defects.
     *
     * @return array{
     *     values: array<string, string>,
     *     issues: array<int, array{code: string, key: string, line: ?int}>
     * }
     */
    public function inspectScope(string $scope): array
    {
        $path = $this->pathForScope($scope);
        $issues = [];

        if (is_file($path)) {
            $content = @file_get_contents($path);

            if ($content === false) {
                return [
                    'values' => [],
                    'issues' => [[
                        'code' => 'unreadable_environment_file',
                        'key' => '[file]',
                        'line' => null,
                    ]],
                ];
            }

            $issues = $this->assignmentStructureIssues($content);

            // An unterminated quoted value is not a valid deployment input,
            // even if the dotenv parser treats it as a multiline string.
            // Do not reuse any parsed values when the structural scan fails.
            foreach ($issues as $issue) {
                if ($issue['code'] === 'malformed_environment_file') {
                    return ['values' => [], 'issues' => $issues];
                }
            }
        }

        try {
            $values = $this->valuesForScope($scope);
        } catch (InvalidFileException) {
            // The parser exception may contain a fragment of the offending
            // line (including secrets). Never copy its message into a plan.
            $issues[] = [
                'code' => 'malformed_environment_file',
                'key' => '[file]',
                'line' => null,
            ];
            $values = [];
        }

        return ['values' => $values, 'issues' => $issues];
    }

    public function contains(string $scope, string $key): bool
    {
        return array_key_exists($key, $this->valuesForScope($scope));
    }

    /**
     * Track names in actual assignments, ignoring commented lines and lines
     * within quoted multiline values. A second assignment is always ambiguous,
     * even when both values happen to be identical. An unclosed quoted value
     * is an incomplete file, even if a dotenv parser accepts it at EOF.
     *
     * @return array<int, array{code: string, key: string, line: ?int}>
     */
    private function assignmentStructureIssues(string $content): array
    {
        $seen = [];
        $issues = [];
        $openQuote = null;
        $quoteStartLine = null;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        foreach ($lines as $index => $line) {
            if ($openQuote !== null) {
                $openQuote = $this->continuingQuote($line, $openQuote);

                if ($openQuote === null) {
                    $quoteStartLine = null;
                }

                continue;
            }

            if (preg_match('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/D', $line, $match) !== 1) {
                continue;
            }

            $key = $match[1];

            if (isset($seen[$key])) {
                $issues[] = [
                    'code' => 'duplicate_environment_key',
                    'key' => $key,
                    'line' => $index + 1,
                ];
            }

            $seen[$key] = true;
            $value = ltrim($match[2], " \t");

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $openQuote = $this->continuingQuote(substr($value, 1), $value[0]);

                if ($openQuote !== null) {
                    $quoteStartLine = $index + 1;
                }
            }
        }

        if ($openQuote !== null) {
            $issues[] = [
                'code' => 'malformed_environment_file',
                'key' => '[file]',
                'line' => $quoteStartLine,
            ];
        }

        return $issues;
    }

    private function continuingQuote(string $line, string $quote): ?string
    {
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $line[$i] === '\\') {
                $i++;

                continue;
            }

            if ($line[$i] === $quote) {
                return null;
            }
        }

        return $quote;
    }

    private function clientEnvironmentPath(): string
    {
        $path = config('client.env_path');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Unable to resolve selected-client environment path.');
        }

        return $path;
    }
}