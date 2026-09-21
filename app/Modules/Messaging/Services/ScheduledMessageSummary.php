<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
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
            'messageTemplateVersion.messageTemplate',
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
        $template = $message->messageTemplateVersion?->messageTemplate;
        $templateName = $template instanceof MessageTemplate
            ? $this->text($template->name)
            : null;
        $templatePreset = $this->templatePreset($message, $template);
        $channel = $message->channel instanceof BackedEnum
            ? $message->channel->value
            : (string) $message->channel;

        return [
            'scheduled_message_id' => (int) $message->getKey(),
            'subject' => $subject,
            'message' => $body,
            'template_name' => $templateName,
            'template_url' => $this->templateUrl($templatePreset),
            'conversation_label' => $this->conversationLabel($message, $templateName, $subject),
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

    private function conversationLabel(
        ScheduledMessage $message,
        ?string $templateName,
        ?string $subject,
    ): string {
        $messageType = trim((string) $message->message_type);
        $scope = trim((string) $message->scope);

        if ($messageType !== '') {
            if (str_starts_with($messageType, 'post_')) {
                $messageTypeLabel = Str::lower(Str::headline(substr($messageType, 5))).' follow-up';
            } else {
                $messageTypeLabel = Str::lower(Str::headline($messageType));
            }

            $scopeLabel = in_array($scope, ['', 'general', 'direct_message'], true)
                ? null
                : Str::lower(Str::headline($scope));
            $noun = str_ends_with($messageTypeLabel, 'message')
                ? null
                : 'message';

            return 'the '.trim(implode(' ', array_filter([
                $scopeLabel,
                $messageTypeLabel,
                $noun,
            ])));
        }

        if ($templateName !== null) {
            return $templateName;
        }

        return $subject ?? 'the message';
    }

    private function templatePreset(
        ScheduledMessage $message,
        ?MessageTemplate $template,
    ): ?MessageTemplatePreset {
        if ($template instanceof MessageTemplate) {
            $preset = MessageTemplatePreset::query()
                ->active()
                ->where('key', $template->key)
                ->first();

            if ($preset instanceof MessageTemplatePreset) {
                return $preset;
            }
        }

        $sourcePath = trim((string) $message->definition_config_path);

        if ($sourcePath === '') {
            return null;
        }

        $preset = MessageTemplatePreset::query()
            ->active()
            ->where('source_config_path', $sourcePath)
            ->first();

        if ($preset instanceof MessageTemplatePreset) {
            return $preset;
        }

        return MessageTemplatePreset::query()
            ->active()
            ->whereHas('assignments', fn ($query) => $query
                ->active()
                ->where('source_config_path', $sourcePath))
            ->first();
    }

    private function templateUrl(?MessageTemplatePreset $preset): ?string
    {
        if (! $preset instanceof MessageTemplatePreset
            || ! Route::has('crm.messaging.message-templates.index')
        ) {
            return null;
        }

        $catalogEntry = $preset->catalogEntries()
            ->active()
            ->orderBy('item_order')
            ->orderBy('id')
            ->first();

        if (! $catalogEntry instanceof MessageTemplateCatalogEntry) {
            return null;
        }

        return route('crm.messaging.message-templates.index', array_filter([
            'channel' => $preset->channel,
            'purpose' => $preset->purpose,
            'module' => $catalogEntry->module_key,
            'group' => $catalogEntry->group_key,
            'preset' => $preset->getKey(),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
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