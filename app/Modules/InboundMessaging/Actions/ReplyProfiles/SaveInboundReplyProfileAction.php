<?php

namespace App\Modules\InboundMessaging\Actions\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyIntent;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Models\InboundReplyRule;
use App\Modules\InboundMessaging\Services\ReplyProfiles\ReplyProfileDefinitionNormalizer;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveInboundReplyProfileAction
{
    public function __construct(
        private readonly ReplyProfileDefinitionNormalizer $normalizer,
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    /** @param array<string, mixed> $definition */
    public function handle(
        array $definition,
        ?InboundReplyProfile $profile = null,
    ): InboundReplyProfile {
        $normalized = $this->normalizer->profile(
            (string) ($definition['key'] ?? ''),
            $definition,
        );

        return DB::transaction(function () use ($normalized, $profile): InboundReplyProfile {
            $locked = InboundReplyProfile::withTrashed()
                ->where('key', $normalized['key'])
                ->lockForUpdate()
                ->first();

            if ($profile instanceof InboundReplyProfile
                && (! $locked instanceof InboundReplyProfile
                    || $locked->getKey() !== $profile->getKey())
            ) {
                throw ValidationException::withMessages([
                    'key' => 'Reply profile keys cannot be changed after creation.',
                ]);
            }

            if (! $profile instanceof InboundReplyProfile
                && $locked instanceof InboundReplyProfile
                && ! $locked->trashed()
            ) {
                throw ValidationException::withMessages([
                    'key' => 'That reply profile key is already in use.',
                ]);
            }

            $profile = $locked ?? new InboundReplyProfile([
                'key' => $normalized['key'],
            ]);

            if ($profile->trashed()) {
                $profile->restore();
            }

            $this->guardIntentChanges($profile, $normalized['intents']);

            $profile->forceFill([
                'label' => $normalized['label'],
                'description' => $normalized['description'],
                'is_active' => $profile->exists
                    ? (bool) $profile->is_active
                    : (bool) $normalized['is_active'],
                'source' => $profile->source ?: 'database',
                'source_version' => $profile->source_version,
                'is_customized' => true,
                'customized_at' => now(),
            ])->save();

            $this->replaceIntents($profile, $normalized['intents']);

            return $profile->fresh(['intents.rules']);
        }, 3);
    }

    /** @param array<string, array<string, mixed>> $intents */
    private function guardIntentChanges(InboundReplyProfile $profile, array $intents): void
    {
        if (! $profile->exists) {
            return;
        }

        $submitted = collect($intents)->keyBy('key');
        $existing = $profile->intents()->get();

        foreach ($existing as $intent) {
            $replacement = $submitted->get($intent->key);
            $removed = ! is_array($replacement);
            $disabled = is_array($replacement)
                && $intent->is_active
                && ! (bool) $replacement['is_active'];

            if (($removed || $disabled)
                && $this->dependencies->intentIsBlocked($profile->key, $intent->key)
            ) {
                throw ValidationException::withMessages([
                    'intents' => "Intent [{$intent->label}] is still referenced by configured automation and cannot be removed or disabled.",
                ]);
            }
        }
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