<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Throwable;

class SendMessageAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly MessageChannelAvailability $messageChannelAvailability,
    ) {}

    public function key(): string
    {
        return 'messaging.dispatch_message';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $definition = SendMessageAutomationDefinition::from($context->input);

        if (! $definition->isValid()) {
            return AutomationActionResult::failed(
                reason: $definition->invalidReason ?? 'invalid_send_message_automation_definition',
                output: ['send_message_definition' => $definition->toMetaPayload()],
            );
        }

        $contact = $context->model('current_contact');

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed('send_message_contact_not_found', output: [
                'send_message_definition' => $definition->toMetaPayload(),
            ]);
        }

        $surface = match ($context->surface) {
            'flow_routes' => 'route_send_message_points',
            null, '' => 'automation_actions',
            default => $context->surface,
        };

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $definition->channel,
            surface: $surface,
            purpose: $definition->purpose,
            scope: $definition->scope,
        )) {
            return AutomationActionResult::skipped('send_message_channel_unavailable', output: [
                'send_message_definition' => $definition->toMetaPayload(),
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
                context: $context->source ?? $contact,
                triggeredAt: now(),
                sendAt: now(),
                behaviorOwner: $context->behaviorOwner,
                occurrenceKey: $context->executionKey,
                meta: array_replace_recursive(
                    ['source' => 'automation'],
                    $context->meta,
                    $definition->meta,
                ),
                criteria: $definition->criteria,
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('send_message_dispatch_failed', output: [
                'error' => $exception->getMessage(),
                'send_message_definition' => $definition->toMetaPayload(),
            ]);
        }

        if ($scheduledMessages === []) {
            return $this->noMessagesResult($definition);
        }

        return AutomationActionResult::completed(
            reason: 'message_scheduled',
            artifacts: $scheduledMessages,
            correlationKey: 'scheduled_message.id',
            correlationType: 'scheduled_message',
            correlation: [
                'scheduled_message_ids' => array_map(
                    fn (ScheduledMessage $message): mixed => $message->getKey(),
                    $scheduledMessages,
                ),
            ],
            output: [
                'scheduled_messages' => array_map(
                    fn (ScheduledMessage $message): array => [
                        'id' => $message->getKey(),
                        'recipient_type' => $message->recipient_type,
                        'recipient_id' => $message->recipient_id,
                        'channel' => $message->channel,
                        'purpose' => $message->purpose,
                        'scope' => $message->scope,
                        'message_type' => $message->message_type,
                        'send_at' => $message->send_at?->toISOString(),
                        'status' => $message->status,
                    ],
                    $scheduledMessages,
                ),
                'send_message_definition' => $definition->toMetaPayload(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function payload(
        SendMessageAutomationDefinition $definition,
        AutomationActionContext $context,
    ): array {
        if ($context->runtimeContext === []) {
            return $definition->payload;
        }

        return array_replace_recursive($definition->payload, [
            'runtime_context' => $context->runtimeContext,
        ]);
    }

    private function noMessagesResult(
        SendMessageAutomationDefinition $definition,
    ): AutomationActionResult {
        $output = [
            'send_message_definition' => $definition->toMetaPayload(),
        ];

        return match ($definition->onNoMessages) {
            'completed' => AutomationActionResult::completed(
                reason: 'send_message_no_messages_scheduled',
                output: $output,
            ),
            'blocked' => AutomationActionResult::blocked(
                reason: 'send_message_no_messages_scheduled',
                output: $output,
            ),
            'failed' => AutomationActionResult::failed(
                reason: 'send_message_no_messages_scheduled',
                output: $output,
            ),
            default => AutomationActionResult::skipped(
                reason: 'send_message_no_messages_scheduled',
                output: $output,
            ),
        };
    }
}
