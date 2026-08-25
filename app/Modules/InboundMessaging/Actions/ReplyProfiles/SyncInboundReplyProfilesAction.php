<?php

namespace App\Modules\InboundMessaging\Actions\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyIntent;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Models\InboundReplyRule;
use App\Modules\InboundMessaging\Services\ReplyProfiles\ReplyProfileDefinitionNormalizer;
use Illuminate\Support\Facades\DB;

final class SyncInboundReplyProfilesAction
{
    public function __construct(
        private readonly ReplyProfileDefinitionNormalizer $normalizer,
    ) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, customized_skipped: int, removed_skipped: int}
     */
    public function handle(bool $force = false): array
    {
        $configured = $this->normalizer->configured();
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'customized_skipped' => 0,
            'removed_skipped' => 0,
        ];

        foreach ($configured['profiles'] as $definition) {
            DB::transaction(function () use ($definition, $configured, $force, &$result): void {
                $profile = InboundReplyProfile::withTrashed()
                    ->where('key', $definition['key'])
                    ->lockForUpdate()
                    ->first();

                if ($profile instanceof InboundReplyProfile && $profile->trashed()) {
                    $result['removed_skipped']++;

                    return;
                }

                if ($profile instanceof InboundReplyProfile
                    && $profile->is_customized
                    && ! $force
                ) {
                    $result['customized_skipped']++;

                    return;
                }

                $sourceVersion = hash('sha256', json_encode(
                    $definition,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
                $attributes = [
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'is_active' => $definition['is_active'],
                    'source' => 'client_config',
                    'source_config_path' => $configured['source'].'.'.$definition['key'],
                    'source_version' => $sourceVersion,
                    'is_customized' => false,
                    'customized_at' => null,
                    'last_synced_at' => now(),
                ];

                if (! $profile instanceof InboundReplyProfile) {
                    $profile = InboundReplyProfile::query()->create([
                        'key' => $definition['key'],
                        ...$attributes,
                    ]);
                    $this->replaceIntents($profile, $definition['intents']);
                    $result['created']++;

                    return;
                }

                if ($profile->source_version === $sourceVersion && ! $force) {
                    $profile->forceFill(['last_synced_at' => now()])->save();
                    $result['unchanged']++;

                    return;
                }

                $profile->forceFill($attributes)->save();
                $this->replaceIntents($profile, $definition['intents']);
                $result['updated']++;
            }, 3);
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>> $intents */
    private function replaceIntents(InboundReplyProfile $profile, array $intents): void
    {
        $profile->intents()->delete();

        foreach (array_values($intents) as $intentDefinition) {
            $intent = $profile->intents()->create([
                'key' => $intentDefinition['key'],
                'label' => $intentDefinition['label'],
                'description' => $intentDefinition['description'],
                'is_active' => $intentDefinition['is_active'],
                'sort_order' => $intentDefinition['sort_order'],
            ]);

            $this->createRules($intent, InboundReplyRule::MATCH_EXACT, $intentDefinition['exact']);
            $this->createRules($intent, InboundReplyRule::MATCH_KEYWORD, $intentDefinition['keywords']);
        }
    }

    /** @param array<int, string> $values */
    private function createRules(InboundReplyIntent $intent, string $matchType, array $values): void
    {
        foreach ($values as $index => $value) {
            $intent->rules()->create([
                'match_type' => $matchType,
                'value' => $value,
                'normalized_value' => $this->normalizer->normalizedRuleValue($matchType, $value),
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ]);
        }
    }
}