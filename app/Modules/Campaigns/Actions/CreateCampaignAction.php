<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Data\CampaignCreationOption;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CreateCampaignAction
{
    public const SOURCE = 'crm_campaign_authoring';
    public const PURPOSE = 'marketing';
    public const SCOPE = 'campaign';
    public const DISPATCH_KEY = 'campaign_step_due';
    public const QUEUE = 'marketing';

    public function __construct(
        private readonly CreateReusableMessageTemplateAction $createReusableMessageTemplate,
        private readonly PublishMessageChainVersionAction $publishMessageChainVersion,
    ) {}

    /**
     * @param array<string, mixed> $firstMessagePayload
     */
    public function handle(
        string $name,
        ?string $description,
        string $channel,
        array $firstMessagePayload,
        CampaignCreationOption $creationOption,
        ?User $createdBy = null,
    ): Campaign {
        $name = trim($name);
        $description = is_string($description) && trim($description) !== ''
            ? trim($description)
            : null;
        $channel = strtolower(trim($channel));

        if ($name === '') {
            throw new InvalidArgumentException('Campaign name is required.');
        }

        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException("Campaign first-message channel [{$channel}] is not supported.");
        }

        return DB::transaction(function () use (
            $name,
            $description,
            $channel,
            $firstMessagePayload,
            $creationOption,
            $createdBy,
        ): Campaign {
            $now = now();
            $campaign = Campaign::query()->create([
                'key' => $this->campaignKey($name),
                'name' => $name,
                'description' => $description,
                'message_chain_id' => null,
                'family_key' => null,
                'priority' => 0,
                'eligibility_filter' => [],
                'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
                'reentry_policy' => Campaign::REENTRY_NEVER,
                'ineligible_behavior' => Campaign::INELIGIBLE_CONTINUE,
                'channel' => $channel,
                'purpose' => self::PURPOSE,
                'scope' => self::SCOPE,
                'status' => Campaign::STATUS_INACTIVE,
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => $now,
                'meta' => [
                    'authoring' => [
                        'source' => self::SOURCE,
                        'creation_intent' => $creationOption->key,
                        'created_by' => $createdBy?->getKey(),
                        'created_at' => $now->toISOString(),
                    ],
                ],
            ]);

            $preset = $this->createReusableMessageTemplate->handle(
                name: mb_substr($name.' — First message', 0, 191),
                channel: $channel,
                payload: $firstMessagePayload,
                context: new ReusableMessageTemplateAuthoringContext(
                    contextKey: 'campaign_step',
                    purpose: self::PURPOSE,
                    scope: self::SCOPE,
                    dispatchKey: self::DISPATCH_KEY,
                    messageType: 'campaign_step',
                    payloadClass: $channel === 'email'
                        ? EmailPayload::class
                        : SmsPayload::class,
                    queue: self::QUEUE,
                    moduleKey: 'campaigns',
                    moduleLabel: 'Campaigns',
                    surface: 'campaigns',
                    groupKey: 'campaign:'.$campaign->key,
                    groupLabel: $campaign->name,
                    usageType: 'campaign_step',
                    selectionContexts: ['campaign_step'],
                    description: 'CRM-authored Campaign message.',
                    itemOrder: 10,
                    contextType: $campaign->getMorphClass(),
                    contextId: (int) $campaign->getKey(),
                    presetMeta: [
                        'campaign_authoring' => [
                            'campaign_key' => $campaign->key,
                            'campaign_step' => 1,
                            'campaign_step_variant_key' => $channel,
                        ],
                    ],
                    catalogMeta: [
                        'campaign_key' => $campaign->key,
                        'campaign_step' => 1,
                        'campaign_step_variant_key' => $channel,
                    ],
                ),
                createdBy: $createdBy,
            );

            $template = $preset->canonicalTemplate;
            $templateVersion = $template?->currentVersion;

            if (! $template instanceof MessageTemplate
                || ! $templateVersion instanceof MessageTemplateVersion
            ) {
                throw new RuntimeException(
                    'Campaign creation could not publish the first message template.',
                );
            }

            $chain = MessageChain::query()->create([
                'key' => 'campaign.'.$campaign->key,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'status' => MessageChain::STATUS_INACTIVE,
                'current_version_id' => null,
                'source' => self::SOURCE,
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => $now,
            ]);

            $this->publishMessageChainVersion->handle(
                messageChain: $chain,
                steps: [[
                    'key' => 'step_1',
                    'name' => 'First message',
                    'sort_order' => 10,
                    'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                    'anchor_key' => null,
                    'offset_seconds' => 0,
                    'day_offset' => 0,
                    'local_time' => null,
                    'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                    'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                    'conditions' => null,
                    'is_active' => true,
                    'variants' => [[
                        'key' => $channel,
                        'sort_order' => 10,
                        'message_template_version_id' => (int) $templateVersion->getKey(),
                        'channel' => $channel,
                        'purpose' => self::PURPOSE,
                        'scope' => self::SCOPE,
                        'message_type' => 'campaign_step',
                        'queue' => self::QUEUE,
                        'dependency_policy' => null,
                        'conditions' => null,
                        'is_active' => true,
                    ]],
                ]],
                exitConditions: [],
                createdBy: $createdBy,
            );

            $campaign->forceFill([
                'message_chain_id' => $chain->getKey(),
            ])->save();

            return $campaign->fresh([
                'messageChain.currentVersion.steps.variants.messageTemplateVersion.messageTemplate',
            ]) ?? $campaign;
        }, 3);
    }

    private function campaignKey(string $name): string
    {
        $base = Str::slug($name, '_');

        if ($base === '') {
            $base = 'campaign';
        }

        $base = mb_substr($base, 0, 70);
        $uuid = str_replace('-', '_', Str::lower((string) Str::uuid()));

        return $base.'_'.$uuid;
    }
}