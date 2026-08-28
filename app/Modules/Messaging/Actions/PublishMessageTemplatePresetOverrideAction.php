<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Data\MessageTemplatePresetPublicationResult;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplateCompositionIdentityResolver;
use App\Modules\Messaging\Services\MessageTemplateCompositionResolver;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use Illuminate\Support\Facades\DB;

final class PublishMessageTemplatePresetOverrideAction
{
    public function __construct(
        private readonly MessageTemplateTokenValidator $messageTemplateTokenValidator,
        private readonly MessageTemplateCompositionResolver $compositionResolver,
        private readonly MessageTemplateCompositionIdentityResolver $compositionIdentityResolver,
        private readonly UpsertMessageTemplateCompositionLayerAction $upsertCompositionLayer,
        private readonly PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
    ) {}

    /** @param array<string, mixed> $submittedPayload */
    public function handle(
        MessageTemplatePreset $preset,
        array $submittedPayload,
        ?User $createdBy = null,
    ): MessageTemplatePresetPublicationResult {
        $messageTemplate = MessageTemplate::query()->firstOrCreate(
            ['key' => $preset->key],
            [
                'name' => $preset->name,
                'description' => $preset->description,
                'channel' => $preset->channel,
                'status' => $preset->isActive()
                    ? MessageTemplate::STATUS_ACTIVE
                    : MessageTemplate::STATUS_INACTIVE,
                'composition_family_key' => $this->compositionIdentityResolver->familyKey(
                    scope: (string) $preset->scope,
                    sourceMessageType: (string) $preset->message_type,
                    campaignTemplate: false,
                ),
                'source' => $preset->source,
                'source_version' => is_int($preset->source_version)
                    ? (string) $preset->source_version
                    : null,
            ],
        );
        $sourcePayload = is_array($preset->payload) ? $preset->payload : [];
        $baselinePayload = $this->compositionResolver->resolveWithoutMessageOverride(
            $messageTemplate,
            $sourcePayload,
        );
        $submittedPayload = $this->preserveTokenFallbacks(
            baseline: $messageTemplate->currentPayload(),
            submitted: $submittedPayload,
        );
        $submittedPayload = $this->preserveTrackingKeys(
            baseline: $messageTemplate->currentPayload(),
            submitted: $submittedPayload,
        );
        $submittedPayload = $this->preserveTrackingKeys(
            baseline: $baselinePayload,
            submitted: $submittedPayload,
        );
        $overridePayload = $this->payloadDelta($baselinePayload, $submittedPayload);

        return DB::transaction(function () use (
            $messageTemplate,
            $preset,
            $sourcePayload,
            $overridePayload,
            $createdBy,
        ): MessageTemplatePresetPublicationResult {
            $existingOverride = MessageTemplateCompositionLayer::query()
                ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
                ->where('message_template_id', $messageTemplate->getKey())
                ->first();

            if ($overridePayload === []) {
                $existingOverride?->delete();

                $messageTemplate->forceFill([
                    'is_customized' => false,
                    'customized_at' => null,
                ])->save();
            } else {
                $override = $this->upsertCompositionLayer->handle(
                    scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
                    channel: (string) $messageTemplate->channel,
                    payload: $overridePayload,
                    messageTemplate: $messageTemplate,
                    source: 'crm',
                    isCustomized: true,
                );

                $messageTemplate->forceFill([
                    'is_customized' => true,
                    'customized_at' => $override->customized_at ?? now(),
                ])->save();
            }

            $version = $this->publishMessageTemplateVersion->handle(
                messageTemplate: $messageTemplate,
                payload: $sourcePayload,
                createdBy: $createdBy,
            );

            $preset->forceFill([
                'tokens' => $this->messageTemplateTokenValidator->tokensFromPayload(
                    $version->payload(),
                ),
            ])->save();

            return new MessageTemplatePresetPublicationResult(
                version: $version,
                overrideCleared: $overridePayload === [],
            );
        });
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function payloadDelta(array $baseline, array $submitted): array
    {
        $delta = [];

        foreach ($submitted as $key => $value) {
            if (! array_key_exists($key, $baseline) || $baseline[$key] !== $value) {
                $delta[$key] = $value;
            }
        }

        return $delta;
    }

    /**
     * Callers that have not adopted missing-field authoring yet must not erase
     * an existing message-specific policy just by editing ordinary copy. An
     * explicit token_fallbacks key, including an empty list, always wins.
     *
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function preserveTokenFallbacks(array $baseline, array $submitted): array
    {
        if (! array_key_exists('token_fallbacks', $submitted)
            && array_key_exists('token_fallbacks', $baseline)
            && is_array($baseline['token_fallbacks'])
        ) {
            $submitted['token_fallbacks'] = $baseline['token_fallbacks'];
        }

        return $submitted;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function preserveTrackingKeys(array $baseline, array $submitted): array
    {
        foreach (['cta', 'secondary_link'] as $key) {
            $baselineLink = $baseline[$key] ?? null;
            $submittedLink = $submitted[$key] ?? null;

            if (! is_array($baselineLink)
                || ! is_array($submittedLink)
                || ! is_string($baselineLink['tracking_key'] ?? null)
                || trim($baselineLink['tracking_key']) === ''
            ) {
                continue;
            }

            $submitted[$key]['tracking_key'] = trim($baselineLink['tracking_key']);
        }

        $baselineCtas = $baseline['ctas'] ?? null;
        $submittedCtas = $submitted['ctas'] ?? null;

        if (is_array($baselineCtas)
            && array_is_list($baselineCtas)
            && is_array($submittedCtas)
            && array_is_list($submittedCtas)
        ) {
            foreach ($submittedCtas as $index => $submittedCta) {
                $baselineCta = $baselineCtas[$index] ?? null;

                if (! is_array($submittedCta)
                    || ! is_array($baselineCta)
                    || ! is_string($baselineCta['tracking_key'] ?? null)
                    || trim($baselineCta['tracking_key']) === ''
                ) {
                    continue;
                }

                $submitted['ctas'][$index]['tracking_key'] = trim(
                    $baselineCta['tracking_key'],
                );
            }
        }

        return $submitted;
    }
}