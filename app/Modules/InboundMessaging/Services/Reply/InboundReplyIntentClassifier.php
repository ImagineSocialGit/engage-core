<?php

namespace App\Modules\InboundMessaging\Services\Reply;

class InboundReplyIntentClassifier
{
    public function classify(?string $replyProfileKey, string $normalizedText): ?string
    {
        if (! is_string($replyProfileKey) || trim($replyProfileKey) === '' || $normalizedText === '') {
            return null;
        }

        $profile = config('messaging.reply_profiles.'.trim($replyProfileKey));
        $intents = is_array($profile) && is_array($profile['intents'] ?? null)
            ? $profile['intents']
            : [];

        $exactText = $this->exactText($normalizedText);

        foreach ($intents as $intentKey => $definition) {
            if (! is_string($intentKey) || ! is_array($definition)) {
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
            if (! is_string($intentKey) || ! is_array($definition)) {
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