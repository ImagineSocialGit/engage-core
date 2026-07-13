<?php

namespace App\Support\TokenContracts;

use App\Support\ConfigContracts\Data\ConfigContractViolation;
use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;
use App\Support\TokenContracts\Data\TokenSourceDefinition;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class TokenContractRegistry
{
    /** @var array<string, TokenSourceDefinition> */
    private array $sources = [];

    /** @var array<string, TokenContextDefinition> */
    private array $contexts = [];

    /**
     * @param iterable<int, TokenSourceProvider> $sourceProviders
     * @param iterable<int, TokenContextProvider> $contextProviders
     */
    public function __construct(iterable $sourceProviders = [], iterable $contextProviders = [])
    {
        foreach ($sourceProviders as $provider) {
            if (! $provider instanceof TokenSourceProvider) {
                throw new InvalidArgumentException('Token source providers must implement TokenSourceProvider.');
            }

            foreach ($provider->sources() as $source) {
                if (! $source instanceof TokenSourceDefinition) {
                    throw new InvalidArgumentException('Token source providers must return TokenSourceDefinition instances.');
                }

                if (isset($this->sources[$source->token])) {
                    throw new InvalidArgumentException("Token source [{$source->token}] is registered more than once.");
                }

                $this->sources[$source->token] = $source;
            }
        }

        foreach ($contextProviders as $provider) {
            if (! $provider instanceof TokenContextProvider) {
                throw new InvalidArgumentException('Token context providers must implement TokenContextProvider.');
            }

            foreach ($provider->contexts() as $context) {
                if (! $context instanceof TokenContextDefinition) {
                    throw new InvalidArgumentException('Token context providers must return TokenContextDefinition instances.');
                }

                if (isset($this->contexts[$context->key])) {
                    throw new InvalidArgumentException("Token context [{$context->key}] is registered more than once.");
                }

                foreach ($context->sourceTokens as $token) {
                    if (! isset($this->sources[$token])) {
                        throw new InvalidArgumentException(
                            "Token context [{$context->key}] references unregistered source [{$token}]."
                        );
                    }
                }

                $this->contexts[$context->key] = $context;
            }
        }

        ksort($this->sources);
        ksort($this->contexts);
    }

    /** @return array<string, TokenSourceDefinition> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string, TokenContextDefinition> */
    public function contexts(): array
    {
        return $this->contexts;
    }

    public function hasContext(string $key): bool
    {
        return isset($this->contexts[$key]);
    }

    public function source(string $token): TokenSourceDefinition
    {
        return $this->sources[$token]
            ?? throw new InvalidArgumentException("Token source [{$token}] is not registered.");
    }

    public function context(string $key): TokenContextDefinition
    {
        return $this->contexts[$key]
            ?? throw new InvalidArgumentException("Token context [{$key}] is not registered.");
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    public function validateModelColumns(): array
    {
        $violations = [];

        foreach ($this->sources as $source) {
            if (! $source->isModelColumn()) {
                continue;
            }

            $modelClass = $source->modelClass;
            $model = new $modelClass;
            $table = $model->getTable();

            if (! Schema::hasColumn($table, $source->column)) {
                $violations[] = new ConfigContractViolation(
                    code: 'token_model_column_missing',
                    path: "reference.tokens.sources.{$source->token}",
                    message: "Token [{$source->token}] references missing column [{$table}.{$source->column}].",
                    meta: [
                        'model' => $source->modelClass,
                        'table' => $table,
                        'column' => $source->column,
                    ],
                );
            }
        }

        return $violations;
    }

    /** @return array<int, string> */
    public function allAuthorableTokens(): array
    {
        $tokens = [];

        foreach ($this->sources as $source) {
            $tokens[] = $source->token;
            array_push($tokens, ...$source->aliases);
        }

        return array_values(array_unique($tokens));
    }

    /** @return array<int, string> */
    public function authorableTokens(string $contextKey): array
    {
        $tokens = [];

        foreach ($this->context($contextKey)->sourceTokens as $sourceToken) {
            $source = $this->source($sourceToken);
            $tokens[] = $source->token;
            array_push($tokens, ...$source->aliases);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * A reusable message with multiple dispatch contexts may only use tokens
     * that every declared context can provide.
     *
     * @param array<int, string> $contextKeys
     * @return array<int, string>
     */
    public function authorableTokensForContexts(array $contextKeys): array
    {
        $contextKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $contextKey): ?string => is_string($contextKey) && trim($contextKey) !== ''
                ? trim($contextKey)
                : null,
            $contextKeys,
        ))));

        if ($contextKeys === []) {
            return [];
        }

        $allowed = null;

        foreach ($contextKeys as $contextKey) {
            $contextTokens = $this->authorableTokens($contextKey);

            $allowed = $allowed === null
                ? $contextTokens
                : array_values(array_intersect($allowed, $contextTokens));
        }

        return $allowed ?? [];
    }
}
