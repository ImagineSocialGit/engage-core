<?php

namespace App\Support\TokenContracts\Data;

use App\Support\TokenContracts\Contracts\ComputedTokenValueProvider;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TokenSourceDefinition
{
    public const TYPE_MODEL_COLUMN = 'model_column';
    public const TYPE_COMPUTED = 'computed';

    /**
     * @param array<int, string> $aliases
     */
    private function __construct(
        public readonly string $token,
        public readonly string $owner,
        public readonly string $label,
        public readonly string $description,
        public readonly string $sourceType,
        public readonly string $sourcePath,
        public readonly array $aliases = [],
        public readonly bool $nullable = true,
        public readonly ?string $modelClass = null,
        public readonly ?string $column = null,
        public readonly ?string $providerClass = null,
    ) {
        foreach ([$token, $owner, $label, $description, $sourcePath] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Token source identity fields must be non-empty strings.');
            }
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/', $token) !== 1) {
            throw new InvalidArgumentException("Token [{$token}] has an invalid canonical name.");
        }

        if ($this->containsMetaSegment($token) || $this->containsMetaSegment($sourcePath)) {
            throw new InvalidArgumentException("Token [{$token}] may not expose arbitrary metadata.");
        }

        foreach ($aliases as $alias) {
            if (! is_string($alias) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_.:-]*$/', $alias) !== 1) {
                throw new InvalidArgumentException("Token [{$token}] has an invalid alias.");
            }

            if ($this->containsMetaSegment($alias)) {
                throw new InvalidArgumentException("Token [{$token}] may not expose a metadata alias.");
            }
        }
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<int, string> $aliases
     */
    public static function modelColumn(
        string $token,
        string $owner,
        string $label,
        string $description,
        string $modelClass,
        string $column,
        array $aliases = [],
        bool $nullable = true,
    ): self {
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Model token [{$token}] must reference an Eloquent model.");
        }

        $column = trim($column);

        if ($column === '' || $column === 'meta' || str_starts_with($column, 'meta.')) {
            throw new InvalidArgumentException("Model token [{$token}] must reference a real non-meta column.");
        }

        return new self(
            token: $token,
            owner: $owner,
            label: $label,
            description: $description,
            sourceType: self::TYPE_MODEL_COLUMN,
            sourcePath: $token,
            aliases: array_values(array_unique($aliases)),
            nullable: $nullable,
            modelClass: $modelClass,
            column: $column,
        );
    }

    /**
     * @param class-string<ComputedTokenValueProvider> $providerClass
     * @param array<int, string> $aliases
     */
    public static function computed(
        string $token,
        string $owner,
        string $label,
        string $description,
        string $sourcePath,
        string $providerClass,
        array $aliases = [],
        bool $nullable = true,
    ): self {
        if (! is_subclass_of($providerClass, ComputedTokenValueProvider::class)) {
            throw new InvalidArgumentException(
                "Computed token [{$token}] must reference an explicit computed-token provider."
            );
        }

        return new self(
            token: $token,
            owner: $owner,
            label: $label,
            description: $description,
            sourceType: self::TYPE_COMPUTED,
            sourcePath: $sourcePath,
            aliases: array_values(array_unique($aliases)),
            nullable: $nullable,
            providerClass: $providerClass,
        );
    }

    public function isModelColumn(): bool
    {
        return $this->sourceType === self::TYPE_MODEL_COLUMN;
    }

    private function containsMetaSegment(string $value): bool
    {
        return in_array('meta', preg_split('/[.:-]+/', strtolower($value)) ?: [], true);
    }
}
