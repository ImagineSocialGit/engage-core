<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DuplicateMessageChainAction
{
    public function __construct(
        private readonly PublishMessageChainVersionAction $publishVersion,
    ) {}

    public function handle(
        MessageChain $source,
        string $key,
        string $name,
        ?string $description = null,
        ?User $createdBy = null,
    ): MessageChain {
        return DB::transaction(function () use (
            $source,
            $key,
            $name,
            $description,
            $createdBy,
        ): MessageChain {
            $sourceChain = MessageChain::query()
                ->with('currentVersion.steps.variants')
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sourceVersion = $sourceChain->requireCurrentVersion();

            if (! $sourceVersion->isPublished()) {
                throw new RuntimeException(
                    "MessageChain [{$sourceChain->key}] current version is not published.",
                );
            }

            $key = $this->requiredString($key, 'MessageChain key', 191);
            $name = $this->requiredString($name, 'MessageChain name', 191);

            if (MessageChain::query()->where('key', $key)->exists()) {
                throw new InvalidArgumentException(
                    "MessageChain key [{$key}] is already in use.",
                );
            }

            $duplicate = MessageChain::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description !== null
                    ? $this->nullableString($description)
                    : $sourceChain->description,
                'status' => MessageChain::STATUS_ACTIVE,
                'source' => 'duplicate',
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => now(),
            ]);

            $definition = $sourceVersion->definition();

            $version = $this->publishVersion->handle(
                messageChain: $duplicate,
                steps: $definition['steps'],
                exitConditions: is_array($definition['exit_conditions'])
                    ? $definition['exit_conditions']
                    : [],
                createdBy: $createdBy,
            );

            $duplicate->setRelation('currentVersion', $version);

            return $duplicate;
        }, 3);
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "{$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}