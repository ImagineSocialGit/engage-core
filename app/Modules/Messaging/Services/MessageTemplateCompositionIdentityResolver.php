<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Support\Str;

class MessageTemplateCompositionIdentityResolver
{
    public function contextKey(
        ?string $templateSetKey,
        ?string $campaignKey,
        bool $campaignTemplate,
    ): ?string {
        if ($campaignTemplate) {
            return $campaignKey !== null
                ? $this->normalizeSegment($campaignKey)
                : null;
        }

        if ($templateSetKey === null
            || $templateSetKey === MessageDefinitionConfigSetResolver::DEFAULT_TEMPLATE_SET_KEY
        ) {
            return null;
        }

        return $this->normalizeSegment($templateSetKey);
    }

    public function familyKey(
        string $scope,
        string $sourceMessageType,
        bool $campaignTemplate,
    ): string {
        if ($campaignTemplate) {
            return 'campaign_message';
        }

        $scope = $this->normalizeSegment($scope);
        $sourceMessageType = $this->normalizeSegment($sourceMessageType);

        return match (true) {
            $scope === 'webinar'
                && in_array($sourceMessageType, ['confirmation', 'confirmations'], true)
                    => 'confirmation',

            $scope === 'webinar'
                && in_array($sourceMessageType, ['opt_in', 'opt_ins'], true)
                    => 'opt_in',

            $scope === 'webinar'
                && in_array($sourceMessageType, ['reminder', 'reminders'], true)
                    => 'reminder',

            $scope === 'webinar'
                && in_array($sourceMessageType, ['post_attended', 'post_missed'], true)
                    => 'post_webinar_follow_up',

            $scope === 'webinar_waitlist'
                && in_array($sourceMessageType, ['alert', 'alerts'], true)
                    => 'waitlist_alert',

            $scope === 'webinar_waitlist'
                && in_array($sourceMessageType, ['opt_in', 'opt_ins'], true)
                    => 'waitlist_opt_in',

            default => $this->normalizeSegment(Str::singular($sourceMessageType)),
        };
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}