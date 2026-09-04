<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use App\Modules\Messaging\Support\CtaTrackingLinkGenerator;
use App\Modules\Messaging\Support\MessageMediaPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateReusableMessageTemplateAction
{
    public const SOURCE = 'crm_reusable';

    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
        private readonly MessageTemplateTokenValidator $messageTemplateTokenValidator,
        private readonly MessageTokenFallbackResolver $messageTokenFallbackResolver,
    ) {}

    /**
     * The calling surface owns the business context. Messaging owns validation,
     * persistence, version publication, and catalog registration.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $name,
        string $channel,
        array $payload,
        ReusableMessageTemplateAuthoringContext $context,
        ?User $createdBy = null,
    ): MessageTemplatePreset {
        $name = trim($name);
        $channel = strtolower(trim($channel));
        $payload = $this->normalizedPayload($channel, $payload);
        $contextData = $this->normalizedContext($context);

        if ($name === '') {
            throw new InvalidArgumentException('Reusable message template name is required.');
        }

        if (mb_strlen($name) > 191) {
            throw new InvalidArgumentException('Reusable message template name may not exceed 191 characters.');
        }

        $issues = $this->messageTemplateTokenValidator->validatePayload(
            payload: $payload,
            dispatchKeys: [$contextData['dispatch_key']],
            channel: $channel,
            purpose: $contextData['purpose'],
            scope: $contextData['scope'],
            surface: $contextData['surface'],
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

        if (array_key_exists('token_fallbacks', $payload)) {
            $policies = $this->messageTokenFallbackResolver->policies($payload);

            if ($policies === []) {
                unset($payload['token_fallbacks']);
            } else {
                $payload['token_fallbacks'] = $policies;
            }
        }

        return DB::transaction(function () use (
            $name,
            $channel,
            $payload,
            $contextData,
            $createdBy,
        ): MessageTemplatePreset {
            $key = 'crm_message_'.Str::lower((string) Str::uuid());
            $now = now();
            $description = $contextData['description']
                ?? 'Reusable CRM-authored message.';

            $preset = MessageTemplatePreset::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'channel' => $channel,
                'purpose' => $contextData['purpose'],
                'scope' => $contextData['scope'],
                'message_type' => $contextData['message_type'],
                'payload_class' => $contextData['payload_class'],
                'queue' => $contextData['queue'],
                'dispatch_keys' => [$contextData['dispatch_key']],
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
                'meta' => $contextData['preset_meta'] !== [] ? $contextData['preset_meta'] : null,
            ]);

            $template = MessageTemplate::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
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
                'purpose' => $contextData['purpose'],
                'scope' => $contextData['scope'],
                'module_key' => $contextData['module_key'],
                'module_label' => $contextData['module_label'],
                'surface' => $contextData['surface'],
                'group_key' => $contextData['group_key'],
                'group_label' => $contextData['group_label'],
                'item_key' => $key,
                'item_label' => $name,
                'item_order' => $contextData['item_order'],
                'usage_type' => $contextData['usage_type'],
                'source' => self::SOURCE,
                'source_config_path' => null,
                'context_type' => $contextData['context_type'],
                'context_id' => $contextData['context_id'],
                'is_active' => true,
                'meta' => $contextData['catalog_meta'] !== [] ? $contextData['catalog_meta'] : null,
            ]);

            return $preset->load([
                'canonicalTemplate.currentVersion',
                'catalogEntries',
            ]);
        }, 3);
    }

    /**
     * @return array{
     *     context_key: string,
     *     purpose: string,
     *     scope: string,
     *     dispatch_key: string,
     *     message_type: string|null,
     *     payload_class: string,
     *     queue: string|null,
     *     module_key: string,
     *     module_label: string,
     *     surface: string,
     *     group_key: string,
     *     group_label: string,
     *     usage_type: string,
     *     description: string|null,
     *     item_order: int,
     *     context_type: string|null,
     *     context_id: int|null,
     *     preset_meta: array<string, mixed>,
     *     catalog_meta: array<string, mixed>
     * }
     */
    private function normalizedContext(
        ReusableMessageTemplateAuthoringContext $context,
    ): array {
        $required = [
            'context key' => $context->contextKey,
            'purpose' => $context->purpose,
            'scope' => $context->scope,
            'dispatch key' => $context->dispatchKey,
            'payload class' => $context->payloadClass,
            'module key' => $context->moduleKey,
            'module label' => $context->moduleLabel,
            'surface' => $context->surface,
            'group key' => $context->groupKey,
            'group label' => $context->groupLabel,
            'usage type' => $context->usageType,
        ];

        foreach ($required as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Reusable message template {$label} is required.");
            }
        }

        $contextType = is_string($context->contextType) && trim($context->contextType) !== ''
            ? trim($context->contextType)
            : null;
        $contextId = $context->contextId !== null && $context->contextId > 0
            ? $context->contextId
            : null;

        if (($contextType === null) !== ($contextId === null)) {
            throw new InvalidArgumentException('Reusable message template context type and context id must be supplied together.');
        }

        $contextKey = trim($context->contextKey);
        $selectionContexts = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $context->selectionContexts,
        ))));

        if (! in_array($contextKey, $selectionContexts, true)) {
            $selectionContexts[] = $contextKey;
        }

        $authoringMeta = [
            'authoring' => [
                'context_key' => $contextKey,
                'selection_contexts' => $selectionContexts,
            ],
        ];

        return [
            'context_key' => $contextKey,
            'purpose' => trim($context->purpose),
            'scope' => trim($context->scope),
            'dispatch_key' => trim($context->dispatchKey),
            'message_type' => is_string($context->messageType) && trim($context->messageType) !== ''
                ? trim($context->messageType)
                : null,
            'payload_class' => trim($context->payloadClass),
            'queue' => is_string($context->queue) && trim($context->queue) !== ''
                ? trim($context->queue)
                : null,
            'module_key' => trim($context->moduleKey),
            'module_label' => trim($context->moduleLabel),
            'surface' => trim($context->surface),
            'group_key' => trim($context->groupKey),
            'group_label' => trim($context->groupLabel),
            'usage_type' => trim($context->usageType),
            'description' => is_string($context->description) && trim($context->description) !== ''
                ? trim($context->description)
                : null,
            'item_order' => max(0, $context->itemOrder),
            'context_type' => $contextType,
            'context_id' => $contextId,
            'preset_meta' => array_replace_recursive($context->presetMeta, $authoringMeta),
            'catalog_meta' => array_replace_recursive($context->catalogMeta, $authoringMeta),
        ];
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

            $normalized = [
                'subject' => $subject,
                'body' => $body,
            ];

            $cta = $this->normalizedCta($payload['cta'] ?? null);

            if ($cta !== []) {
                $normalized['cta'] = $cta;
            }

            if (array_key_exists('media', $payload)) {
                MessageMediaPayload::assertValid($payload['media']);
                $normalized['media'] = $payload['media'];
            }

            if (array_key_exists('token_fallbacks', $payload)) {
                $normalized['token_fallbacks'] = $payload['token_fallbacks'];
            }

            return $normalized;
        }

        if ($channel === 'sms') {
            $message = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';

            if ($message === '') {
                throw new InvalidArgumentException('Reusable SMS templates require message copy.');
            }

            $normalized = [
                'message' => $message,
            ];

            if (array_key_exists('token_fallbacks', $payload)) {
                $normalized['token_fallbacks'] = $payload['token_fallbacks'];
            }

            return $normalized;
        }

        throw new InvalidArgumentException("Reusable message template channel [{$channel}] is not supported.");
    }

    /** @return array<string, string> */
    private function normalizedCta(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $label = is_string($value['label'] ?? null) ? trim($value['label']) : '';
        $url = is_string($value['url'] ?? null) ? trim($value['url']) : '';

        if ($label === '' || $url === '') {
            return [];
        }

        $cta = [
            'label' => $label,
            'url' => $url,
        ];
        $trackingKey = $value['tracking_key'] ?? null;

        if (CtaTrackingLinkGenerator::isValidTrackingKey($trackingKey)) {
            $cta = [
                'tracking_key' => trim((string) $trackingKey),
                ...$cta,
            ];
        }

        return $cta;
    }
}