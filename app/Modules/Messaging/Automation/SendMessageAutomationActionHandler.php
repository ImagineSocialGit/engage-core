<?php

namespace App\Modules\Messaging\Automation;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Events\AutomationMessageScheduled;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\DirectMessageTemplateResolver;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use BackedEnum;
use Throwable;

class SendMessageAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessage,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly DirectMessageTemplateResolver $directTemplates,
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

        $exactTemplateDefinition = null;
        $contextualChannel = $this->contextualTemplateChannel(
            $definition,
            $context,
        );
        $candidateKey = $definition->directTemplateCandidateKey(
            $contextualChannel,
        );

        if ($definition->usesContextualTemplateSelection()
            && $candidateKey === null
        ) {
            return AutomationActionResult::failed(
                'send_message_contextual_template_unresolved',
                output: [
                    'contextual_channel' => $contextualChannel,
                    'send_message_definition' => $definition->toMetaPayload(),
                ],
            );
        }

        if ($candidateKey !== null) {
            $exactTemplateDefinition = $this->directTemplates->definition($candidateKey);

            if (! is_array($exactTemplateDefinition) && $definition->hasAuthoritativeTemplateKey()) {
                return AutomationActionResult::failed('send_message_template_missing', output: [
                    'message_template_key' => $candidateKey,
                    'send_message_definition' => $definition->toMetaPayload(),
                ]);
            }
        }

        $channel = is_array($exactTemplateDefinition)
            ? (string) ($exactTemplateDefinition['channel'] ?? '')
            : (string) $definition->channel;
        $purpose = is_array($exactTemplateDefinition)
            ? (string) ($exactTemplateDefinition['purpose'] ?? '')
            : (string) $definition->purpose;
        $scope = is_array($exactTemplateDefinition)
            ? (string) ($exactTemplateDefinition['scope'] ?? '')
            : (string) $definition->scope;
        $dispatchKeys = is_array($exactTemplateDefinition)
            ? $this->dispatchKeys($exactTemplateDefinition)
            : $definition->dispatchKeys;

        $surface = match ($context->surface) {
            'flow_routes' => 'route_send_message_points',
            null, '' => 'automation_actions',
            default => $context->surface,
        };

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $channel,
            surface: $surface,
            purpose: $purpose,
            scope: $scope,
        )) {
            return AutomationActionResult::skipped('send_message_channel_unavailable', output: [
                'send_message_definition' => $definition->toMetaPayload(),
            ]);
        }

        try {
            $scheduledMessages = $this->dispatchMessage->handle(
                recipient: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                dispatchKeys: $dispatchKeys,
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
                definitions: is_array($exactTemplateDefinition)
                    ? [$exactTemplateDefinition]
                    : [],
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

        if ($definition->isReply() && count($scheduledMessages) !== 1) {
            return AutomationActionResult::failed(
                reason: 'send_message_reply_requires_one_scheduled_message',
                output: [
                    'scheduled_message_ids' => array_map(
                        fn (ScheduledMessage $message): mixed =>
                            $message->getKey(),
                        $scheduledMessages,
                    ),
                    'send_message_definition' =>
                        $definition->toMetaPayload(),
                ],
            );
        }

        $result = AutomationActionResult::completed(
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

        event(new AutomationMessageScheduled(
            context: $context,
            definition: $definition,
            scheduledMessages: $scheduledMessages,
        ));

        return $result;
    }

    /** @return array<int, string> */
    private function dispatchKeys(array $definition): array
    {
        $keys = $definition['dispatch_keys'] ?? $definition['dispatch_key'] ?? [];
        $keys = is_string($keys) ? [$keys] : $keys;

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): ?string => is_string($key) && trim($key) !== ''
                ? trim($key)
                : null,
            $keys,
        ))));
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
            'tokens' => $context->runtimeContext,
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

    private function contextualTemplateChannel(
        SendMessageAutomationDefinition $definition,
        AutomationActionContext $context,
    ): ?string {
        if (! $definition->usesContextualTemplateSelection()) {
            return null;
        }

        $path = $definition->messageTemplateChannelContextPath;

        if ($path === null) {
            return null;
        }

        $value = data_get($context->executionContext, $path);

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}