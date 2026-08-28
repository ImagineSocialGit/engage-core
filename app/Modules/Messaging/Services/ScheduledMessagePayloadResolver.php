<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageRenderContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

class ScheduledMessagePayloadResolver
{
    public function __construct(
        private readonly MessageRecipientPayloadResolver $recipientPayloadResolver,
        private readonly ScheduledMessagePayloadCanonicalizer $payloadCanonicalizer,
        private readonly MessageChainExecutionContextResolver $chainExecutionContextResolver,
        private readonly ScheduledMessageComponentComposer $componentComposer,
        private readonly MessageTokenFallbackResolver $tokenFallbackResolver,
    ) {}

    public function resolve(
        ScheduledMessage $scheduledMessage,
    ): EmailMessage|SmsMessage {
        $payloadData = $scheduledMessage->message_template_version_id === null
            ? $this->legacyPayloadData($scheduledMessage)
            : $this->versionedPayloadData($scheduledMessage);

        return $this->instantiatePayload(
            scheduledMessage: $scheduledMessage,
            payloadData: $payloadData,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function versionedPayloadData(
        ScheduledMessage $scheduledMessage,
    ): array {
        $version = $this->messageTemplateVersion($scheduledMessage);
        $templatePayload = $this->componentComposer->compose(
            scheduledMessage: $scheduledMessage,
            primaryPayload: $version->payload(),
        );
        $runtimePayload = is_array($scheduledMessage->payload)
            ? $scheduledMessage->payload
            : [];
        $renderContext = $this->renderContext($scheduledMessage);

        if ($renderContext instanceof ScheduledMessageRenderContext) {
            return $this->withOperationalFields(
                scheduledMessage: $scheduledMessage,
                payload: $this->tokenFallbackResolver->apply(
                    $this->mergeFrozenRenderContext(
                        templatePayload: $templatePayload,
                        runtimePayload: $runtimePayload,
                        values: is_array($renderContext->values)
                            ? $renderContext->values
                            : [],
                    ),
                ),
            );
        }

        $runtimePayload = $this->withChainExecutionContext(
            scheduledMessage: $scheduledMessage,
            runtimePayload: $runtimePayload,
        );

        $recipient = $scheduledMessage->recipient()->first();

        if (! $recipient instanceof Model) {
            throw new InvalidArgumentException(
                'Scheduled message recipient could not be resolved for rendering.',
            );
        }

        $resolved = $this->recipientPayloadResolver->resolve(
            recipient: $recipient,
            channel: $scheduledMessage->channel,
            purpose: $scheduledMessage->purpose,
            scope: $scheduledMessage->scope,
            messageType: $scheduledMessage->message_type,
            definitionPayload: $templatePayload,
            payload: $runtimePayload,
        );

        if (! is_array($resolved)) {
            throw new InvalidArgumentException(
                'Scheduled message destination could not be resolved for rendering.',
            );
        }

        $resolved = $this->tokenFallbackResolver->apply($resolved);

        $canonical = $this->payloadCanonicalizer->forPersistence(
            payloadClass: (string) $scheduledMessage->payload_class,
            payload: $resolved,
            channel: (string) $scheduledMessage->channel,
            purpose: (string) $scheduledMessage->purpose,
            scope: (string) $scheduledMessage->scope,
            messageType: (string) $scheduledMessage->message_type,
            conditions: is_array(data_get($scheduledMessage->meta, 'conditions'))
                ? data_get($scheduledMessage->meta, 'conditions')
                : [],
        );

        // The immutable template policy has already been applied. Do not let
        // canonical config fallback reintroduce current control data into the
        // provider-ready payload for a pinned historical version.
        unset($canonical['token_fallbacks']);

        $values = is_array($canonical['tokens'] ?? null)
            ? $canonical['tokens']
            : [];

        if ($values !== [] || $this->tokenFallbackResolver->hasTokenReferences($templatePayload)) {
            $renderContext = ScheduledMessageRenderContext::query()->firstOrCreate(
                ['scheduled_message_id' => $scheduledMessage->getKey()],
                [
                    'values' => $values,
                    'content_hash' => $this->contentHash($values),
                    'rendered_at' => now(),
                    'expires_at' => null,
                ],
            );

            $values = is_array($renderContext->values)
                ? $renderContext->values
                : [];

            $canonical['tokens'] = $values;
            $scheduledMessage->setRelation('renderContext', $renderContext);
        } else {
            unset($canonical['tokens']);
        }

        $this->removePersistedTokens(
            scheduledMessage: $scheduledMessage,
            runtimePayload: $runtimePayload,
        );

        return $this->withOperationalFields(
            scheduledMessage: $scheduledMessage,
            payload: $canonical,
        );
    }

    /**
     * @param array<string, mixed> $runtimePayload
     * @return array<string, mixed>
     */
    private function withChainExecutionContext(
        ScheduledMessage $scheduledMessage,
        array $runtimePayload,
    ): array {
        if ($scheduledMessage->message_chain_enrollment_id === null) {
            return $runtimePayload;
        }

        $enrollment = $scheduledMessage->relationLoaded('messageChainEnrollment')
            ? $scheduledMessage->getRelation('messageChainEnrollment')
            : $scheduledMessage->messageChainEnrollment()->first();

        if (! $enrollment instanceof MessageChainEnrollment) {
            throw new InvalidArgumentException(
                'Scheduled message chain enrollment could not be resolved for rendering.',
            );
        }

        $runtimePayload['tokens'] = array_replace_recursive(
            $this->chainExecutionContextResolver->resolve($enrollment),
            is_array($runtimePayload['tokens'] ?? null)
                ? $runtimePayload['tokens']
                : [],
        );

        return $runtimePayload;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyPayloadData(
        ScheduledMessage $scheduledMessage,
    ): array {
        $payload = array_replace_recursive(
            [
                'channel' => $scheduledMessage->channel,
                'purpose' => $scheduledMessage->purpose,
                'scope' => $scheduledMessage->scope,
                'message_type' => $scheduledMessage->message_type,
            ],
            is_array($scheduledMessage->payload)
                ? $scheduledMessage->payload
                : [],
        );

        return $this->withProviderIdempotencyKey(
            scheduledMessage: $scheduledMessage,
            payload: $this->tokenFallbackResolver->apply($payload),
        );
    }

    /**
     * @param array<string, mixed> $templatePayload
     * @param array<string, mixed> $runtimePayload
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function mergeFrozenRenderContext(
        array $templatePayload,
        array $runtimePayload,
        array $values,
    ): array {
        unset($runtimePayload['tokens']);

        $payload = array_replace_recursive(
            $templatePayload,
            $runtimePayload,
        );

        if ($values !== []) {
            $payload['tokens'] = $values;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withOperationalFields(
        ScheduledMessage $scheduledMessage,
        array $payload,
    ): array {
        $payload = array_replace_recursive($payload, [
            'channel' => $scheduledMessage->channel,
            'purpose' => $scheduledMessage->purpose,
            'scope' => $scheduledMessage->scope,
            'message_type' => $scheduledMessage->message_type,
        ]);

        return $this->withProviderIdempotencyKey(
            scheduledMessage: $scheduledMessage,
            payload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withProviderIdempotencyKey(
        ScheduledMessage $scheduledMessage,
        array $payload,
    ): array {
        $delivery = [
            'scheduled_message_id' => (int) $scheduledMessage->getKey(),
        ];

        if (filled($scheduledMessage->provider_idempotency_key)) {
            $delivery['provider_idempotency_key'] =
                $scheduledMessage->provider_idempotency_key;
        }

        $payload['meta'] = array_replace_recursive(
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            [
                'delivery' => $delivery,
            ],
        );

        return $payload;
    }

    private function messageTemplateVersion(
        ScheduledMessage $scheduledMessage,
    ): MessageTemplateVersion {
        $version = $scheduledMessage->relationLoaded('messageTemplateVersion')
            ? $scheduledMessage->getRelation('messageTemplateVersion')
            : $scheduledMessage->messageTemplateVersion()->first();

        if (! $version instanceof MessageTemplateVersion) {
            throw new InvalidArgumentException(
                'Scheduled message has no resolvable template version.',
            );
        }

        return $version;
    }

    private function renderContext(
        ScheduledMessage $scheduledMessage,
    ): ?ScheduledMessageRenderContext {
        $renderContext = $scheduledMessage->relationLoaded('renderContext')
            ? $scheduledMessage->getRelation('renderContext')
            : $scheduledMessage->renderContext()->first();

        return $renderContext instanceof ScheduledMessageRenderContext
            ? $renderContext
            : null;
    }

    /**
     * @param array<string, mixed> $runtimePayload
     */
    private function removePersistedTokens(
        ScheduledMessage $scheduledMessage,
        array $runtimePayload,
    ): void {
        if (! array_key_exists('tokens', $runtimePayload)) {
            return;
        }

        unset($runtimePayload['tokens']);

        $scheduledMessage->forceFill([
            'payload' => $runtimePayload,
        ])->saveQuietly();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function contentHash(array $values): string
    {
        try {
            $encoded = json_encode(
                $this->normalizeAssociativeArray($values),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Scheduled message render context could not be encoded.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeAssociativeArray(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeAssociativeArray($value);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    private function instantiatePayload(
        ScheduledMessage $scheduledMessage,
        array $payloadData,
    ): EmailMessage|SmsMessage {
        $payloadClass = $scheduledMessage->payload_class;

        if (! is_string($payloadClass) || ! class_exists($payloadClass)) {
            throw new InvalidArgumentException(
                'Scheduled message payload class is invalid.',
            );
        }

        if (! method_exists($payloadClass, 'fromArray')) {
            throw new InvalidArgumentException(
                "Payload class [{$payloadClass}] must define fromArray().",
            );
        }

        $payload = $payloadClass::fromArray($payloadData);

        if (! $payload instanceof EmailMessage
            && ! $payload instanceof SmsMessage
        ) {
            throw new InvalidArgumentException(
                "Payload class [{$payloadClass}] must implement a supported message payload contract.",
            );
        }

        return $payload;
    }
}