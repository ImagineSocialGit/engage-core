<?php

namespace App\Modules\InboundMessaging\Services\Reply;

class InboundReplyTextNormalizer
{
    public function normalize(?string $body): string
    {
        if (! is_string($body) || trim($body) === '') {
            return '';
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($this->startsQuotedHistory($trimmed) || $this->startsSignature($trimmed)) {
                break;
            }

            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            $kept[] = rtrim($line);
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $kept)) ?? '');
    }

    private function startsQuotedHistory(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        return preg_match('/^(on .+ wrote:|-----original message-----|from:\s.+|_{5,})$/i', $line) === 1;
    }

    private function startsSignature(string $line): bool
    {
        return $line === '--'
            || preg_match('/^sent from my\b/i', $line) === 1;
    }
}