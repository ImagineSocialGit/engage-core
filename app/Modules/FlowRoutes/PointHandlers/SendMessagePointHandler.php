<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Data\Points\SendMessagePointDefinition;
use App\Modules\FlowRoutes\Models\Point;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use Illuminate\Support\Carbon;
use Throwable;

class SendMessagePointHandler implements PointHandler
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly MessageChannelAvailability $messageChannelAvailability,
    ) {}

    public function type(): string
    {
        return Point::TYPE_SEND_MESSAGE;
    }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = SendMessagePointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_send_message_point_definition',
                meta: [
                    'send_message_definition' => $definition->toMetaPayload(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            );
        }

        $contact = Contact::query()->find($context->progress->contact_id);

        if (! $contact) {
            return PointExecutionResult::failed(
                reason: 'send_message_contact_not_found',
                meta: [
                    'contact_id' => $context->progress->contact_id,
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $definition->channel,
            surface: 'route_send_message_points',
            purpose: $definition->purpose,
            scope: $definition->scope,
        )) {
            return PointExecutionResult::skipped(
                reason: 'send_message_channel_unavailable',
                meta: [
                    'send_message_definition' => $definition->toMetaPayload(),
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        try {
            $scheduledMessages = $this->dispatchMessage->handle(
                recipient: $contact,
                channel: $definition->channel,
                purpose: $definition->purpose,
                scope: $definition->scope,
                dispatchKeys: $definition->dispatchKeys,
                payload: $this->payload($definition, $context),
                context: $context->progress,
                triggeredAt: now(),
                anchor: $this->anchor($definition, $context),
                meta: $this->meta($definition, $context),
                criteria: $definition->criteria,
            );
        } catch (Throwable $exception) {
            return PointExecutionResult::failed(
                reason: 'send_message_dispatch_failed',
                meta: [
                    'error' => $exception->getMessage(),
                    'send_message_definition' => $definition->toMetaPayload(),
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                ],
            );
        }

        if ($scheduledMessages === []) {
            return $this->noMessagesResult($definition, $context);
        }

        return PointExecutionResult::completed(
            reason: 'message_scheduled',
            meta: [
                'scheduled_messages' => array_map(
                    fn (ScheduledMessage $scheduledMessage): array => [
                        'id' => $scheduledMessage->getKey(),
                        'recipient_type' => $scheduledMessage->recipient_type,
                        'recipient_id' => $scheduledMessage->recipient_id,
                        'channel' => $scheduledMessage->channel,
                        'purpose' => $scheduledMessage->purpose,
                        'scope' => $scheduledMessage->scope,
                        'message_type' => $scheduledMessage->message_type,
                        'send_at' => $scheduledMessage->send_at?->toISOString(),
                        'status' => $scheduledMessage->status,
                    ],
                    $scheduledMessages,
                ),
                'send_message_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        SendMessagePointDefinition $definition,
        PointExecutionContext $context,
    ): array {
        return array_replace_recursive(
            $this->renderArray($definition->payload, $context),
            [
                'runtime_context' => [
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_id' => $context->progress->flow_route_id,
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                    'contact_id' => $context->progress->contact_id,
                    'contact_status_id' => $context->progress->contact_status_id,
                    'workflow_profile_id' => $context->progress->contact_workflow_profile_id,
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(
        SendMessagePointDefinition $definition,
        PointExecutionContext $context,
    ): array {
        return array_replace_recursive(
            [
                'source' => 'flow_routes',
                'flow_route' => [
                    'flow_route_progress_id' => $context->progress->getKey(),
                    'flow_route_id' => $context->progress->flow_route_id,
                    'flow_route_point_id' => $context->flowRoutePoint->getKey(),
                    'point_id' => $context->flowRoutePoint->point_id,
                ],
            ],
            $definition->meta,
        );
    }

    private function anchor(
        SendMessagePointDefinition $definition,
        PointExecutionContext $context,
    ): Carbon|string|null {
        if ($definition->anchor === null) {
            return null;
        }

        if (! is_string($definition->anchor)) {
            return $definition->anchor;
        }

        return $this->renderText($definition->anchor, $context);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function renderArray(array $values, PointExecutionContext $context): array
    {
        $rendered = [];

        foreach ($values as $key => $value) {
            $rendered[$key] = match (true) {
                is_string($value) => $this->renderText($value, $context),
                is_array($value) => $this->renderArray($value, $context),
                default => $value,
            };
        }

        return $rendered;
    }

    private function renderText(string $value, PointExecutionContext $context): string
    {
        return strtr($value, [
            '{contact.id}' => (string) $context->progress->contact_id,
            '{contact_status.id}' => (string) $context->progress->contact_status_id,
            '{workflow_profile.id}' => (string) $context->progress->contact_workflow_profile_id,
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{point.id}' => (string) $context->flowRoutePoint->point_id,
        ]);
    }

    private function noMessagesResult(
        SendMessagePointDefinition $definition,
        PointExecutionContext $context,
    ): PointExecutionResult {
        $meta = [
            'send_message_definition' => $definition->toMetaPayload(),
            'flow_route_progress_id' => $context->progress->getKey(),
            'flow_route_point_id' => $context->flowRoutePoint->getKey(),
        ];

        return match ($definition->onNoMessages) {
            'completed' => PointExecutionResult::completed(
                reason: 'send_message_no_messages_scheduled',
                meta: $meta,
            ),

            'blocked' => PointExecutionResult::blocked(
                reason: 'send_message_no_messages_scheduled',
                meta: $meta,
            ),

            'failed' => PointExecutionResult::failed(
                reason: 'send_message_no_messages_scheduled',
                meta: $meta,
            ),

            default => PointExecutionResult::skipped(
                reason: 'send_message_no_messages_scheduled',
                meta: $meta,
            ),
        };
    }
}