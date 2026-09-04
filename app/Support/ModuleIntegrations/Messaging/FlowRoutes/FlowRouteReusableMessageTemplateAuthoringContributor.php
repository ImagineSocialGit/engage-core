<?php

namespace App\Support\ModuleIntegrations\Messaging\FlowRoutes;

use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;

final class FlowRouteReusableMessageTemplateAuthoringContributor implements ReusableMessageTemplateAuthoringOptionContributor
{
    public function __construct(
        private readonly MessageChannelAvailability $channels,
    ) {}

    public function options(): iterable
    {
        $order = 300;

        foreach (['transactional', 'marketing'] as $purpose) {
            foreach (['email', 'sms'] as $channel) {
                if (! $this->channels->isVisibleForSurface(
                    channel: $channel,
                    surface: 'route_send_message_points',
                    purpose: $purpose,
                    scope: 'general',
                )) {
                    continue;
                }

                $channelLabel = $channel === 'sms' ? 'SMS' : 'Email';
                $purposeLabel = $purpose === 'marketing' ? 'Marketing' : 'Service / transactional';

                yield new ReusableMessageTemplateAuthoringOption(
                    key: 'flow_routes.'.$purpose.'.'.$channel,
                    label: 'Route message — '.$purposeLabel.' — '.$channelLabel,
                    description: $purpose === 'marketing'
                        ? 'Reusable marketing copy that a Route can send after its trigger and conditions are satisfied.'
                        : 'Reusable service or transactional copy that a Route can send after its trigger and conditions are satisfied.',
                    channel: $channel,
                    context: new ReusableMessageTemplateAuthoringContext(
                        contextKey: RouteAuthoringMessageTemplateEligibilityResolver::SELECTION_CONTEXT,
                        purpose: $purpose,
                        scope: 'general',
                        dispatchKey: 'flow_route_send_message',
                        messageType: 'flow_route_message',
                        payloadClass: $channel === 'sms' ? SmsPayload::class : EmailPayload::class,
                        queue: $purpose === 'marketing' ? 'marketing' : 'notifications',
                        moduleKey: 'flow_routes',
                        moduleLabel: 'Flow Routes',
                        surface: 'route_send_message_points',
                        groupKey: 'flow_routes:direct:'.$purpose.':'.$channel,
                        groupLabel: 'Flow Route Messages',
                        usageType: 'flow_route_direct',
                        selectionContexts: [RouteAuthoringMessageTemplateEligibilityResolver::SELECTION_CONTEXT],
                        description: 'Reusable message created for direct Flow Route sends.',
                    ),
                    namePlaceholder: $purpose === 'marketing'
                        ? 'Lead nurture follow-up — '.$channelLabel
                        : 'Appointment acknowledgement — '.$channelLabel,
                    order: $order++,
                );
            }
        }
    }
}