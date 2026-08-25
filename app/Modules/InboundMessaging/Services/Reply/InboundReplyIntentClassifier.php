<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Models\InboundReplyRule;
use Illuminate\Support\Facades\Schema;

class InboundReplyIntentClassifier
{
    public function classify(?string $replyProfileKey, string $normalizedText): ?string
    {
        if (! is_string($replyProfileKey)
            || trim($replyProfileKey) === ''
            || $normalizedText === ''
        ) {
            return null;
        }

        $replyProfileKey = trim($replyProfileKey);

        if (Schema::hasTable('inbound_reply_profiles')) {
            $profile = InboundReplyProfile::withTrashed()
                ->with('activeIntents.activeRules')
                ->where('key', $replyProfileKey)
                ->first();

            if ($profile instanceof InboundReplyProfile) {
                if ($profile->trashed() || ! $profile->is_active) {
                    return null;
                }

                return $this->classifyDatabaseProfile($profile, $normalizedText);
            }
        }

        return $this->classifyConfiguredProfile($replyProfileKey, $normalizedText);
    }

    private function classifyDatabaseProfile(
        InboundReplyProfile $profile,
        string $normalizedText,
    ): ?string {
        $exactText = $this->exactText($normalizedText);

        foreach ($profile->activeIntents as $intent) {
            foreach ($intent->activeRules->where('match_type', InboundReplyRule::MATCH_EXACT) as $rule) {
                if ($rule->normalized_value === $exactText && $exactText !== '') {
                    return (string) $intent->key;
                }
            }
        }

        foreach ($profile->activeIntents as $intent) {
            foreach ($intent->activeRules->where('match_type', InboundReplyRule::MATCH_KEYWORD) as $rule) {
                if ($this->matches($normalizedText, $rule->value)) {
                    return (string) $intent->key;
                }
            }
        }

        return null;
    }

    private function classifyConfiguredProfile(
        string $replyProfileKey,
        string $normalizedText,
    ): ?string {
        $profiles = config('inbound_messaging.reply_profiles');

        if (! is_array($profiles) || ! array_key_exists($replyProfileKey, $profiles)) {
            $profiles = config('messaging.reply_profiles', []);
        }

        $profile = is_array($profiles)
            ? ($profiles[$replyProfileKey] ?? null)
            : null;
        $intents = is_array($profile) && is_array($profile['intents'] ?? null)
            ? $profile['intents']
            : [];
        $exactText = $this->exactText($normalizedText);

        foreach ($intents as $intentKey => $definition) {
            if (! is_string($intentKey)
                || ! is_array($definition)
                || ! (bool) ($definition['is_active'] ?? $definition['enabled'] ?? true)
            ) {
                continue;
            }

            $exact = is_array($definition['exact'] ?? null)
                ? $definition['exact']
                : [];

            foreach ($exact as $candidate) {
                if ($this->exactText($candidate) === $exactText && $exactText !== '') {
                    return trim($intentKey) !== '' ? trim($intentKey) : null;
                }
            }
        }

        foreach ($intents as $intentKey => $definition) {
            if (! is_string($intentKey)
                || ! is_array($definition)
                || ! (bool) ($definition['is_active'] ?? $definition['enabled'] ?? true)
            ) {
                continue;
            }

            $keywords = is_array($definition['keywords'] ?? null)
                ? $definition['keywords']
                : [];

            foreach ($keywords as $keyword) {
                if ($this->matches($normalizedText, $keyword)) {
                    return trim($intentKey) !== '' ? trim($intentKey) : null;
                }
            }
        }

        return null;
    }

    private function exactText(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/^[\pP\pS\s]+|[\pP\pS\s]+$/u', '', $value) ?? $value;

        return trim($value);
    }

    private function matches(string $text, mixed $keyword): bool
    {
        if (! is_string($keyword) || trim($keyword) === '') {
            return false;
        }

        $keyword = trim($keyword);
        $pattern = '/(?<![\pL\pN])'.preg_quote($keyword, '/').'(?![\pL\pN])/iu';

        return preg_match($pattern, $text) === 1;
    }
}