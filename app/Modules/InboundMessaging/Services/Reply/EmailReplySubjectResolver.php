<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ScheduledMessage;

class EmailReplySubjectResolver
{
    public function resolve(
        InboundMessage $inboundMessage,
        ?ScheduledMessage $correlated = null,
        ?string $requestedSubject = null,
    ): string {
        $subject = $this->nullableString($requestedSubject)
            ?? $this->nullableString($inboundMessage->subject)
            ?? $this->nullableString(data_get($correlated?->payload, 'subject'))
            ?? 'Your message';

        $subject = preg_replace('/[\r\n]+/u', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;
        $subject = preg_replace('/^(?:\s*re\s*:\s*)+/iu', '', $subject) ?? $subject;
        $subject = trim($subject);

        if ($subject === '') {
            $subject = 'Your message';
        }

        return mb_substr('Re: '.$subject, 0, 998);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}