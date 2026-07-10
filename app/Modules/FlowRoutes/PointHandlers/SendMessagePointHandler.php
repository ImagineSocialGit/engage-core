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

    public function type(): string { return Point::TYPE_SEND_MESSAGE; }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $definition = SendMessagePointDefinition::from($context->definition, $context->settings);

        if (! $definition->isValid()) {
            return PointExecutionResult::failed($definition->invalidReason ?? 'invalid_send_message_point_definition', [
                'send_message_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        $contact = Contact::query()->find($context->progress->contact_id);

        if (! $contact) {
            return PointExecutionResult::failed('send_message_contact_not_found', [
                'contact_id' => $context->progress->contact_id,
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $definition->channel,
            surface: 'route_send_message_points',
            purpose: $definition->purpose,
            scope: $definition->scope,
        )) {
            return PointExecutionResult::skipped('send_message_channel_unavailable', [
                'send_message_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
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
                sendAt: now(),
                behaviorOwner: $context->flowRoutePoint,
                occurrenceKey: $this->occurrenceKey($context),
                meta: $this->meta($definition, $context),
                criteria: $definition->criteria,
            );
        } catch (Throwable $exception) {
            return PointExecutionResult::failed('send_message_dispatch_failed', [
                'error' => $exception->getMessage(),
                'send_message_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        if ($scheduledMessages === []) {
            return $this->noMessagesResult($definition, $context);
        }

        if ($context->progressItem) {
            $context->progressItem->forceFill([
                'created_subject_type' => $scheduledMessages[0]->getMorphClass(),
                'created_subject_id' => $scheduledMessages[0]->getKey(),
                'correlation_key' => 'scheduled_message.id',
                'correlation_type' => 'scheduled_message',
                'correlation' => ['scheduled_message_ids' => array_map(fn (ScheduledMessage $message) => $message->getKey(), $scheduledMessages)],
            ])->save();
        }

        return PointExecutionResult::completed('message_scheduled', [
            'scheduled_messages' => array_map(fn (ScheduledMessage $scheduledMessage): array => [
                'id' => $scheduledMessage->getKey(),
                'recipient_type' => $scheduledMessage->recipient_type,
                'recipient_id' => $scheduledMessage->recipient_id,
                'channel' => $scheduledMessage->channel,
                'purpose' => $scheduledMessage->purpose,
                'scope' => $scheduledMessage->scope,
                'message_type' => $scheduledMessage->message_type,
                'send_at' => $scheduledMessage->send_at?->toISOString(),
                'status' => $scheduledMessage->status,
            ], $scheduledMessages),
            'send_message_definition' => $definition->toMetaPayload(),
            'flow_routes' => $context->flowRouteProvenance(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(SendMessagePointDefinition $definition, PointExecutionContext $context): array
    {
        return array_replace_recursive($this->renderArray($definition->payload, $context), [
            'runtime_context' => $context->flowRouteProvenance() + [
                'contact_id' => $context->progress->contact_id,
                'contact_status_id' => $context->progress->contact_status_id,
                'workflow_profile_id' => $context->progress->contact_workflow_profile_id,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function meta(SendMessagePointDefinition $definition, PointExecutionContext $context): array
    {
        return array_replace_recursive([
            'source' => 'flow_routes',
            'flow_route' => $context->flowRouteProvenance(),
        ], $this->renderArray($definition->meta, $context));
    }

    private function anchor(SendMessagePointDefinition $definition, PointExecutionContext $context): Carbon|string|null
    {
        if ($definition->anchor === null) return null;
        return is_string($definition->anchor) ? $this->renderText($definition->anchor, $context) : $definition->anchor;
    }

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
            '{flow_route_progress.id}' => (string) $context->progress->getKey(),
            '{flow_route_plan.id}' => (string) $context->plan?->getKey(),
            '{flow_route_plan_item.id}' => (string) $context->planItem?->getKey(),
            '{flow_route_progress_item.id}' => (string) $context->progressItem?->getKey(),
            '{flow_route.id}' => (string) $context->progress->flow_route_id,
            '{flow_route_point.id}' => (string) $context->flowRoutePoint->getKey(),
            '{point.id}' => (string) $context->flowRoutePoint->point_id,
            '{subject.type}' => (string) $context->progress->subject_type,
            '{subject.id}' => (string) $context->progress->subject_id,
        ]);
    }

    private function noMessagesResult(SendMessagePointDefinition $definition, PointExecutionContext $context): PointExecutionResult
    {
        $meta = [
            'send_message_definition' => $definition->toMetaPayload(),
            'flow_routes' => $context->flowRouteProvenance(),
        ];

        return match ($definition->onNoMessages) {
            'completed' => PointExecutionResult::completed('send_message_no_messages_scheduled', $meta),
            'blocked' => PointExecutionResult::blocked('send_message_no_messages_scheduled', $meta),
            'failed' => PointExecutionResult::failed('send_message_no_messages_scheduled', $meta),
            default => PointExecutionResult::skipped('send_message_no_messages_scheduled', $meta),
        };
    }
    private function occurrenceKey(PointExecutionContext $context): string
    {
        return implode(':', array_filter([
            'flow_route_point',
            $context->progressItem?->getKey(),
            $context->progress->getKey(),
            $context->flowRoutePoint->getKey(),
        ], fn (mixed $value): bool => $value !== null));
    }

}
