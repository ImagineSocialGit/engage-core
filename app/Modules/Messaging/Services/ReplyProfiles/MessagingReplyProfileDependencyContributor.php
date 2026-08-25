<?php

namespace App\Modules\Messaging\Services\ReplyProfiles;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\ReplyHandling\Contracts\ReplyProfileDependencyContributor;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MessagingReplyProfileDependencyContributor implements ReplyProfileDependencyContributor
{
    public function dependencies(): iterable
    {
        return [
            ...$this->messageChainDependencies(),
            ...$this->assignmentDependencies(),
            ...$this->scheduledMessageDependencies(),
        ];
    }

    /** @return array<int, ReplyProfileDependency> */
    private function messageChainDependencies(): array
    {
        if (! Schema::hasTable('message_chain_step_variants')) {
            return [];
        }

        return MessageChainStepVariant::query()
            ->whereNotNull('reply_profile_key')
            ->where('reply_profile_key', '!=', '')
            ->with('messageChainStep.messageChainVersion.messageChain')
            ->get()
            ->groupBy(function (MessageChainStepVariant $variant): string {
                $chainId = $variant->messageChainStep?->messageChainVersion?->message_chain_id;

                return trim((string) $variant->reply_profile_key).':'.(string) $chainId;
            })
            ->map(function ($variants): ?ReplyProfileDependency {
                $variant = $variants->first();
                $step = $variant?->messageChainStep;
                $version = $step?->messageChainVersion;
                $chain = $version?->messageChain;
                $profileKey = trim((string) ($variant?->reply_profile_key ?? ''));

                if ($profileKey === '' || ! $chain instanceof MessageChain) {
                    return null;
                }

                $active = $chain->status === MessageChain::STATUS_ACTIVE
                    && (int) $chain->current_version_id === (int) $version->getKey()
                    && $variants->contains(fn (MessageChainStepVariant $item): bool =>
                        (bool) $item->is_active
                        && (bool) $item->messageChainStep?->is_active);

                return new ReplyProfileDependency(
                    key: 'messaging:message_chain:'.$chain->getKey().':'.$profileKey,
                    profileKey: $profileKey,
                    intentKey: null,
                    moduleKey: 'messaging',
                    type: 'message_chain',
                    label: (string) $chain->name,
                    detail: $active
                        ? 'The current active message journey assigns this reply profile.'
                        : 'A retained message-journey version assigns this reply profile.',
                    active: $active,
                    url: route('crm.messaging.message-templates.index'),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, ReplyProfileDependency> */
    private function assignmentDependencies(): array
    {
        if (! Schema::hasTable('message_template_preset_assignments')) {
            return [];
        }

        return MessageTemplatePresetAssignment::query()
            ->whereNotNull('reply_profile_key')
            ->where('reply_profile_key', '!=', '')
            ->with('messageTemplatePreset')
            ->get()
            ->map(function (MessageTemplatePresetAssignment $assignment): ReplyProfileDependency {
                $profileKey = trim((string) $assignment->reply_profile_key);
                $label = $assignment->messageTemplatePreset?->name
                    ?? Str::headline((string) ($assignment->definition_key ?: $assignment->message_type));
                $context = collect([
                    $assignment->campaign_key ? 'Campaign '.Str::headline($assignment->campaign_key) : null,
                    $assignment->campaign_step ? 'step '.$assignment->campaign_step : null,
                    strtoupper((string) $assignment->channel),
                ])->filter()->implode(' · ');

                return new ReplyProfileDependency(
                    key: 'messaging:assignment:'.$assignment->getKey(),
                    profileKey: $profileKey,
                    intentKey: null,
                    moduleKey: 'messaging',
                    type: 'message_assignment',
                    label: (string) $label,
                    detail: $context !== ''
                        ? $context
                        : 'A message assignment carries this reply profile.',
                    active: (bool) $assignment->is_active,
                    url: route('crm.messaging.message-templates.index'),
                );
            })
            ->values()
            ->all();
    }

    /** @return array<int, ReplyProfileDependency> */
    private function scheduledMessageDependencies(): array
    {
        if (! Schema::hasTable('scheduled_messages')) {
            return [];
        }

        return ScheduledMessage::query()
            ->select('reply_profile_key')
            ->selectRaw('COUNT(*) as message_count')
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as open_count',
                [ScheduledMessage::STATUS_PENDING, ScheduledMessage::STATUS_SENDING],
            )
            ->whereNotNull('reply_profile_key')
            ->where('reply_profile_key', '!=', '')
            ->groupBy('reply_profile_key')
            ->get()
            ->map(function (ScheduledMessage $summary): ReplyProfileDependency {
                $profileKey = trim((string) $summary->reply_profile_key);
                $messageCount = (int) $summary->getAttribute('message_count');
                $openCount = (int) $summary->getAttribute('open_count');

                return new ReplyProfileDependency(
                    key: 'messaging:scheduled_messages:'.$profileKey,
                    profileKey: $profileKey,
                    intentKey: null,
                    moduleKey: 'messaging',
                    type: 'scheduled_messages',
                    label: 'Scheduled message history',
                    detail: $messageCount.' message'.($messageCount === 1 ? '' : 's')
                        .' retain this profile for reply correlation'
                        .($openCount > 0 ? "; {$openCount} are pending or sending." : '.'),
                    active: $openCount > 0,
                );
            })
            ->values()
            ->all();
    }
}