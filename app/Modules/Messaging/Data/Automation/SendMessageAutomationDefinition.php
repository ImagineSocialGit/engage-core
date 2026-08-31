<?php

namespace App\Modules\Messaging\Data\Automation;

class SendMessageAutomationDefinition
{
    public const ROLE_INITIATORY = 'initiatory';
    public const ROLE_REPLY = 'reply';
    public const ROLES = [self::ROLE_INITIATORY, self::ROLE_REPLY];
    public const REPLY_CHANNEL_CONTEXT_PATH =
        'automation_event.payload.inbound_message.channel';

    /**
     * @param array<string, string> $messageTemplateKeysByChannel
     * @param array<int, string> $dispatchKeys
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?string $messageTemplateKey,
        public readonly ?string $legacyMessageTemplatePresetKey,
        public readonly array $messageTemplateKeysByChannel,
        public readonly ?string $messageTemplateChannelContextPath,
        public readonly string $messageRole,
        public readonly ?string $channel,
        public readonly ?string $purpose,
        public readonly ?string $scope,
        public readonly array $dispatchKeys = [],
        public readonly array $payload = [],
        public readonly array $criteria = [],
        public readonly mixed $anchor = null,
        public readonly string $onNoMessages = 'skipped',
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $messageTemplateKey = self::string($input, 'message_template_key');
        $legacyMessageTemplatePresetKey = self::string($input, 'message_template_preset_key');
        $messageTemplateKeysByChannel = self::templateKeysByChannel($input);
        $messageTemplateChannelContextPath = self::string(
            $input,
            'message_template_channel_context_path',
        );
        $messageRole = self::string($input, 'message_role')
            ?? ($messageTemplateKeysByChannel !== []
                ? self::ROLE_REPLY
                : self::ROLE_INITIATORY);
        $channel = self::string($input, 'channel');
        $purpose = self::string($input, 'purpose');
        $scope = self::string($input, 'scope');
        $dispatchKeys = self::dispatchKeys($input);

        $invalidReason = match (true) {
            ! in_array($messageRole, self::ROLES, true) =>
                'send_message_role_invalid',
            $messageTemplateKey !== null && $messageTemplateKeysByChannel !== [] =>
                'send_message_conflicting_template_selection',
            $legacyMessageTemplatePresetKey !== null && $messageTemplateKeysByChannel !== [] =>
                'send_message_conflicting_template_selection',
            $messageRole === self::ROLE_REPLY
                && $messageTemplateKeysByChannel === [] =>
                'send_message_reply_templates_missing',
            $messageRole === self::ROLE_REPLY
                && $messageTemplateChannelContextPath
                    !== self::REPLY_CHANNEL_CONTEXT_PATH =>
                'send_message_reply_channel_context_invalid',
            $messageRole === self::ROLE_INITIATORY
                && $messageTemplateKeysByChannel !== [] =>
                'send_message_initiatory_template_selection_invalid',
            $messageTemplateKeysByChannel !== [] && $messageTemplateChannelContextPath === null =>
                'send_message_missing_template_channel_context_path',
            $messageTemplateKey !== null => null,
            $messageTemplateKeysByChannel !== [] => null,
            $channel === null => 'send_message_missing_channel',
            $purpose === null => 'send_message_missing_purpose',
            $scope === null => 'send_message_missing_scope',
            $dispatchKeys === [] => 'send_message_missing_dispatch_keys',
            default => null,
        };

        return new self(
            messageTemplateKey: $messageTemplateKey,
            legacyMessageTemplatePresetKey: $legacyMessageTemplatePresetKey,
            messageTemplateKeysByChannel: $messageTemplateKeysByChannel,
            messageTemplateChannelContextPath: $messageTemplateChannelContextPath,
            messageRole: $messageRole,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            dispatchKeys: $dispatchKeys,
            payload: is_array($input['payload'] ?? null) ? $input['payload'] : [],
            criteria: is_array($input['criteria'] ?? null) ? $input['criteria'] : [],
            anchor: $input['anchor'] ?? null,
            onNoMessages: self::string($input, 'on_no_messages') ?? 'skipped',
            invalidReason: $invalidReason,
            meta: is_array($input['meta'] ?? null) ? $input['meta'] : [],
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    public function usesContextualTemplateSelection(): bool
    {
        return $this->messageTemplateKeysByChannel !== [];
    }

    public function isReply(): bool
    {
        return $this->messageRole === self::ROLE_REPLY;
    }

    public function directTemplateCandidateKey(?string $channel = null): ?string
    {
        if ($this->messageTemplateKey !== null) {
            return $this->messageTemplateKey;
        }

        if ($this->messageTemplateKeysByChannel !== []) {
            return $channel !== null
                ? ($this->messageTemplateKeysByChannel[$channel] ?? null)
                : null;
        }

        return $this->legacyMessageTemplatePresetKey;
    }

    public function hasAuthoritativeTemplateKey(): bool
    {
        return $this->messageTemplateKey !== null
            || $this->messageTemplateKeysByChannel !== [];
    }

    /** @return array<string, mixed> */
    public function toMetaPayload(): array
    {
        return array_filter([
            'message_template_key' => $this->messageTemplateKey,
            'message_template_preset_key' => $this->legacyMessageTemplatePresetKey,
            'message_template_keys_by_channel' => $this->messageTemplateKeysByChannel,
            'message_template_channel_context_path' => $this->messageTemplateChannelContextPath,
            'message_role' => $this->messageRole,
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'dispatch_keys' => $this->dispatchKeys,
            'payload' => $this->payload,
            'criteria' => $this->criteria,
            'anchor' => $this->anchor,
            'on_no_messages' => $this->onNoMessages,
            'meta' => $this->meta,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function templateKeysByChannel(array $input): array
    {
        $values = $input['message_template_keys_by_channel'] ?? [];

        if (! is_array($values) || array_is_list($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $channel => $templateKey) {
            if (! is_string($channel)
                || trim($channel) === ''
                || ! is_string($templateKey)
                || trim($templateKey) === ''
            ) {
                continue;
            }

            $normalized[trim($channel)] = trim($templateKey);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private static function dispatchKeys(array $input): array
    {
        $dispatchKeys = $input['dispatch_keys'] ?? $input['dispatch_key'] ?? [];

        if (is_string($dispatchKeys)) {
            $dispatchKeys = [$dispatchKeys];
        }

        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? trim($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }
}