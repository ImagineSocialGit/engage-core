<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use BackedEnum;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class ScheduledMessageSummary
{
    /** @return array<string, mixed> */
    public function present(ScheduledMessage $message): array
    {
        $message->loadMissing([
            'messageTemplateVersion',
            'latestContentEdit',
        ]);

        $templatePayload = $message->messageTemplateVersion
            ? $message->messageTemplateVersion->payload()
            : [];
        $payload = array_replace_recursive(
            is_array($templatePayload) ? $templatePayload : [],
            is_array($message->payload) ? $message->payload : [],
            is_array($message->latestContentEdit?->override_payload)
                ? $message->latestContentEdit->override_payload
                : [],
        );
        $subject = $this->text($payload['subject'] ?? null);
        $body = $this->body($payload);
        $channel = $message->channel instanceof BackedEnum
            ? $message->channel->value
            : (string) $message->channel;

        return [
            'subject' => $subject,
            'message' => $body,
            'name' => $subject
                ?: ($body !== '' ? Str::limit($body, 80) : 'Outbound message'),
            'channel' => $channel !== '' ? Str::headline($channel) : 'Message',
            'occurred_at' => $message->send_at?->toIso8601String(),
            'occurred_at_label' => $message->send_at
                ? $message->send_at
                    ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
                    ->format('M j, Y g:i A T')
                : null,
            'status' => Str::headline((string) $message->status),
            'url' => $this->url($message),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function body(array $payload): string
    {
        $value = $payload['message']
            ?? $payload['body']
            ?? $payload['message_body']
            ?? null;

        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace('/<\s*br\s*\/?>/i', "\n", $value) ?? $value;
        $value = preg_replace('/<\/(p|div|li)>/i', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/\n{3,}/", "\n\n", $value) ?? $value);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function url(ScheduledMessage $message): ?string
    {
        if (! Route::has('crm.messaging.outbound.index')) {
            return null;
        }

        $parameters = ['period' => 'all'];

        if ($message->recipient_id !== null
            && $message->recipient_type === (new Contact())->getMorphClass()
        ) {
            $parameters['contact_id'] = $message->recipient_id;
        }

        return route('crm.messaging.outbound.index', $parameters);
    }
}