<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Services\WebinarScheduleProfileChainDefinitionBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncWebinarScheduleProfileChainsAction
{
    private const SOURCE = 'webinar_schedule_profile';

    public function __construct(
        private readonly WebinarScheduleProfileChainDefinitionBuilder $definitionBuilder,
        private readonly PublishMessageChainVersionAction $publishVersion,
    ) {}

    /**
     * @return array{
     *     chains_created: int,
     *     chains_updated: int,
     *     chains_skipped: int,
     *     chain_versions_published: int,
     *     chain_versions_reused: int,
     *     chain_bindings_created: int,
     *     chain_bindings_updated: int,
     *     chain_bindings_disabled: int,
     *     chains_deferred: int
     * }
     */
    public function handle(
        WebinarScheduleProfile $profile,
        bool $force = false,
    ): array {
        if (MessageTemplate::query()->doesntExist()) {
            return [
                ...$this->emptyResult(),
                'chains_deferred' => 1,
            ];
        }

        $profile = $profile->fresh(['items']) ?? $profile->load('items');
        $definitions = $this->definitionBuilder->build($profile);

        return DB::transaction(function () use (
            $profile,
            $definitions,
            $force,
        ): array {
            $result = $this->emptyResult();
            $activeBindingAreas = [];
            $activeChainIds = [];

            foreach ($definitions as $bindingKey => $definition) {
                $chain = $this->syncChain(
                    profile: $profile,
                    bindingKey: $bindingKey,
                    definition: $definition,
                    force: $force,
                    result: $result,
                );
                $activeChainIds[] = (int) $chain->getKey();

                foreach ($definition['message_area_keys'] as $messageAreaKey) {
                    $messageAreaKey = $this->normalizeSegment($messageAreaKey);
                    $activeBindingAreas[] = $messageAreaKey;
                    $this->syncBinding(
                        profile: $profile,
                        chain: $chain,
                        bindingKey: $bindingKey,
                        messageAreaKey: $messageAreaKey,
                        dispatchKey: $definition['dispatch_key'],
                        surface: $definition['surface'],
                        result: $result,
                    );
                }
            }

            $staleBindingsQuery = $profile->messageChainBindings()
                ->where('is_active', true);

            if ($activeBindingAreas !== []) {
                $staleBindingsQuery->whereNotIn(
                    'message_area_key',
                    array_values(array_unique($activeBindingAreas)),
                );
            }

            $staleBindings = $staleBindingsQuery->get();
            $staleChainIds = $staleBindings
                ->pluck('message_chain_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($staleBindings->isNotEmpty()) {
                $result['chain_bindings_disabled'] +=
                    WebinarScheduleProfileChainBinding::query()
                        ->whereIn('id', $staleBindings->modelKeys())
                        ->update(['is_active' => false]);
            }

            if ($staleChainIds !== []) {
                MessageChain::query()
                    ->whereIn('id', $staleChainIds)
                    ->where('source', self::SOURCE)
                    ->where('is_customized', false)
                    ->when(
                        $activeChainIds !== [],
                        fn ($query) => $query->whereNotIn('id', $activeChainIds),
                    )
                    ->update([
                        'status' => MessageChain::STATUS_INACTIVE,
                    ]);
            }

            return $result;
        }, 3);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, int> $result
     */
    private function syncChain(
        WebinarScheduleProfile $profile,
        string $bindingKey,
        array $definition,
        bool $force,
        array &$result,
    ): MessageChain {
        $key = $this->chainKey($profile, $bindingKey);
        $chain = MessageChain::query()
            ->where('key', $key)
            ->lockForUpdate()
            ->first();
        $attributes = [
            'key' => $key,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'status' => $profile->is_active
                && $profile->status === WebinarScheduleProfile::STATUS_ACTIVE
                    ? MessageChain::STATUS_ACTIVE
                    : MessageChain::STATUS_INACTIVE,
            'source' => self::SOURCE,
            'source_version' => $profile->source_version !== null
                ? (string) $profile->source_version
                : null,
        ];

        if (! $chain instanceof MessageChain) {
            $chain = MessageChain::query()->create([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ]);
            $result['chains_created']++;
        } elseif ($chain->is_customized && ! $force) {
            if (! $chain->current_version_id) {
                throw new RuntimeException(
                    "Customized Webinar MessageChain [{$chain->key}] has no current version.",
                );
            }

            $result['chains_skipped']++;

            return $chain;
        } else {
            $chain->forceFill([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ])->save();
            $result['chains_updated']++;
        }

        $versionCountBefore = MessageChainVersion::query()
            ->where('message_chain_id', $chain->getKey())
            ->count();

        $this->publishVersion->handle(
            messageChain: $chain,
            steps: $definition['steps'],
            exitConditions: [],
        );

        $versionCountAfter = MessageChainVersion::query()
            ->where('message_chain_id', $chain->getKey())
            ->count();

        if ($versionCountAfter > $versionCountBefore) {
            $result['chain_versions_published']++;
        } else {
            $result['chain_versions_reused']++;
        }

        return $chain;
    }

    /**
     * @param array<string, int> $result
     */
    private function syncBinding(
        WebinarScheduleProfile $profile,
        MessageChain $chain,
        string $bindingKey,
        string $messageAreaKey,
        string $dispatchKey,
        string $surface,
        array &$result,
    ): void {
        $binding = $profile->messageChainBindings()
            ->where('message_area_key', $messageAreaKey)
            ->first();
        $attributes = [
            'key' => $this->normalizeSegment($bindingKey),
            'message_chain_id' => $chain->getKey(),
            'dispatch_key' => $this->normalizeSegment($dispatchKey),
            'surface' => $this->normalizeSegment($surface),
            'is_active' => $profile->is_active
                && $profile->status === WebinarScheduleProfile::STATUS_ACTIVE,
        ];

        if (! $binding instanceof WebinarScheduleProfileChainBinding) {
            $profile->messageChainBindings()->create([
                'message_area_key' => $messageAreaKey,
                ...$attributes,
            ]);
            $result['chain_bindings_created']++;

            return;
        }

        $binding->forceFill($attributes);

        if ($binding->isDirty()) {
            $binding->save();
            $result['chain_bindings_updated']++;
        }
    }

    /**
     * @return array<string, int>
     */
    private function emptyResult(): array
    {
        return [
            'chains_created' => 0,
            'chains_updated' => 0,
            'chains_skipped' => 0,
            'chain_versions_published' => 0,
            'chain_versions_reused' => 0,
            'chain_bindings_created' => 0,
            'chain_bindings_updated' => 0,
            'chain_bindings_disabled' => 0,
            'chains_deferred' => 0,
        ];
    }

    private function chainKey(
        WebinarScheduleProfile $profile,
        string $bindingKey,
    ): string {
        $key = $this->chainKeyPrefix($profile)
            .$this->normalizeSegment($bindingKey);

        if (mb_strlen($key) <= 191) {
            return $key;
        }

        return mb_substr($key, 0, 126).'.'.hash('sha256', $key);
    }

    private function chainKeyPrefix(
        WebinarScheduleProfile $profile,
    ): string {
        return 'webinar.schedule_profile.'
            .$this->normalizeSegment($profile->key).'.';
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}