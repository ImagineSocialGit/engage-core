<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Validation\ValidationException;

final class CreateCampaignScheduleMessageAction
{
    public function __construct(
        private readonly CreateReusableMessageTemplateAction $createTemplate,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(
        Campaign $campaign,
        MessageChainVersion $currentVersion,
        string $name,
        string $channel,
        array $payload,
        ?User $createdBy = null,
    ): MessageTemplatePreset {
        if ((int) $currentVersion->message_chain_id !== (int) $campaign->message_chain_id
            || ! $currentVersion->isPublished()
            || ! in_array($channel, ['email', 'sms'], true)) {
            throw ValidationException::withMessages([
                'new_step.template' => 'Choose a published Campaign schedule and a supported message channel.',
            ]);
        }

        $lastStep = $currentVersion->steps()
            ->where('is_active', true)
            ->reorder()
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();
        $variant = $lastStep?->variants()
            ->where('is_active', true)
            ->first();

        if (! $variant instanceof MessageChainStepVariant) {
            throw ValidationException::withMessages([
                'new_step.template.name' => 'The Campaign needs a published message schedule before you can add a message.',
            ]);
        }

        return $this->createTemplate->handle(
            name: $name,
            channel: $channel,
            payload: $payload,
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'campaign_step',
                purpose: (string) $variant->purpose,
                scope: (string) $variant->scope,
                dispatchKey: CreateCampaignAction::DISPATCH_KEY,
                messageType: 'campaign_step',
                payloadClass: $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
                queue: $variant->channel === $channel && $variant->queue
                    ? $variant->queue
                    : CreateCampaignAction::QUEUE,
                moduleKey: 'campaigns',
                moduleLabel: 'Campaigns',
                surface: 'campaigns',
                groupKey: 'campaign:'.$campaign->key,
                groupLabel: $campaign->name,
                usageType: 'campaign_step',
                selectionContexts: ['campaign_step'],
                description: 'CRM-authored Campaign message.',
                itemOrder: 10 * ($currentVersion->steps()->count() + 1),
                contextType: $campaign->getMorphClass(),
                contextId: (int) $campaign->getKey(),
                presetMeta: ['campaign_authoring' => [
                    'campaign_key' => $campaign->key,
                    'campaign_step_variant_key' => $channel,
                ]],
                catalogMeta: [
                    'campaign_key' => $campaign->key,
                    'campaign_step_variant_key' => $channel,
                ],
            ),
            createdBy: $createdBy,
        );
    }
}