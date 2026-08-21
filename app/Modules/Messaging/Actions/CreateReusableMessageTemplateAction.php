<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateReusableMessageTemplateAction
{
    public const SOURCE = 'crm_reusable';
    public const SURFACE = 'broadcasts';
    public const MODULE_KEY = 'broadcasts';
    public const MODULE_LABEL = 'Broadcasts';
    public const GROUP_KEY_PREFIX = 'saved_broadcast_messages';
    public const GROUP_LABEL = 'Saved Broadcast Messages';
    public const USAGE_TYPE = 'broadcast_reuse';

    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
        private readonly MessageTemplateTokenValidator $messageTemplateTokenValidator,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $name,
        string $channel,
        string $purpose,
        string $scope,
        string $dispatchKey,
        ?string $messageType,
        string $payloadClass,
        ?string $queue,
        array $payload,
        ?User $createdBy = null,
    ): MessageTemplatePreset {
        $name = trim($name);
        $channel = trim($channel);
        $purpose = trim($purpose);
        $scope = trim($scope);
        $dispatchKey = trim($dispatchKey);
        $messageType = is_string($messageType) && trim($messageType) !== ''
            ? trim($messageType)
            : null;
        $payloadClass = trim($payloadClass);
        $queue = is_string($queue) && trim($queue) !== '' ? trim($queue) : null;
        $payload = $this->normalizedPayload($channel, $payload);

        if ($name === '') {
            throw new InvalidArgumentException('Reusable message template name is required.');
        }

        if (mb_strlen($name) > 191) {
            throw new InvalidArgumentException('Reusable message template name may not exceed 191 characters.');
        }

        foreach ([
            'purpose' => $purpose,
            'scope' => $scope,
            'dispatch key' => $dispatchKey,
            'payload class' => $payloadClass,
        ] as $label => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("Reusable message template {$label} is required.");
            }
        }

        $issues = $this->messageTemplateTokenValidator->validatePayload(
            payload: $payload,
            dispatchKeys: [$dispatchKey],
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            surface: self::SURFACE,
            path: 'payload',
        );

        $errors = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['level'] ?? null) === 'error',
        ));

        if ($errors !== []) {
            $message = $errors[0]['message'] ?? 'Reusable message template contains an invalid token.';

            throw new InvalidArgumentException(is_string($message) ? $message : 'Reusable message template contains an invalid token.');
        }

        return DB::transaction(function () use (
            $name,
            $channel,
            $purpose,
            $scope,
            $dispatchKey,
            $messageType,
            $payloadClass,
            $queue,
            $payload,
            $createdBy,
        ): MessageTemplatePreset {
            $key = 'crm_broadcast_'.Str::lower((string) Str::uuid());
            $now = now();

            $preset = MessageTemplatePreset::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => 'Reusable CRM-authored Broadcast message.',
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'message_type' => $messageType,
                'payload_class' => $payloadClass,
                'queue' => $queue,
                'dispatch_keys' => [$dispatchKey],
                'payload' => $payload,
                'tokens' => $this->messageTemplateTokenValidator->tokensFromPayload($payload),
                'status' => MessageTemplatePreset::STATUS_ACTIVE,
                'is_active' => true,
                'source' => self::SOURCE,
                'source_config_path' => null,
                'source_version' => 1,
                'is_customized' => true,
                'customized_at' => $now,
                'last_synced_at' => null,
                'meta' => null,
            ]);

            $template = MessageTemplate::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => 'Reusable CRM-authored Broadcast message.',
                'channel' => $channel,
                'status' => MessageTemplate::STATUS_ACTIVE,
                'composition_context_key' => null,
                'composition_family_key' => null,
                'current_version_id' => null,
                'source' => self::SOURCE,
                'source_version' => '1',
                'is_customized' => true,
                'customized_at' => $now,
            ]);

            $this->publishMessageTemplateVersion->handle(
                messageTemplate: $template,
                payload: $payload,
                createdBy: $createdBy,
            );

            MessageTemplateCatalogEntry::query()->create([
                'message_template_preset_id' => $preset->getKey(),
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $scope,
                'module_key' => self::MODULE_KEY,
                'module_label' => self::MODULE_LABEL,
                'surface' => self::SURFACE,
                'group_key' => self::groupKey($channel),
                'group_label' => self::groupLabel($channel),
                'item_key' => $key,
                'item_label' => $name,
                'item_order' => 1000,
                'usage_type' => self::USAGE_TYPE,
                'source' => self::SOURCE,
                'source_config_path' => null,
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
                'meta' => null,
            ]);

            return $preset->load([
                'canonicalTemplate.currentVersion',
                'catalogEntries',
            ]);
        }, 3);
    }


    public static function groupKey(string $channel): string
    {
        $channel = strtolower(trim($channel));

        return self::GROUP_KEY_PREFIX.'_'.$channel;
    }

    public static function groupLabel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        return self::GROUP_LABEL.' — '.match ($channel) {
            'email' => 'Email',
            'sms' => 'SMS',
            default => strtoupper($channel),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizedPayload(string $channel, array $payload): array
    {
        if ($channel === 'email') {
            $subject = is_string($payload['subject'] ?? null) ? trim($payload['subject']) : '';
            $body = is_string($payload['body'] ?? null) ? trim($payload['body']) : '';

            if ($subject === '' || $body === '') {
                throw new InvalidArgumentException('Reusable email templates require a subject and body.');
            }

            return [
                'subject' => $subject,
                'body' => $body,
            ];
        }

        if ($channel === 'sms') {
            $message = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';

            if ($message === '') {
                throw new InvalidArgumentException('Reusable SMS templates require message copy.');
            }

            return [
                'message' => $message,
            ];
        }

        throw new InvalidArgumentException("Reusable message template channel [{$channel}] is not supported.");
    }
}