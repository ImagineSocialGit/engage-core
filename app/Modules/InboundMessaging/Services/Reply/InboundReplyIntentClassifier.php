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