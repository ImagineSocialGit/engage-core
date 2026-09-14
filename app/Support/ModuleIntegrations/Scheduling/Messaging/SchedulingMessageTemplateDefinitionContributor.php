<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Modules\Messaging\Contracts\MessageTemplateDefinitionContributor;
use App\Modules\Messaging\Data\MessageTemplateDefinitionContribution;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;

final class SchedulingMessageTemplateDefinitionContributor implements MessageTemplateDefinitionContributor
{
    private const DISPATCH_KEY = 'scheduling_appointment';
    private const PURPOSE = 'transactional';
    private const SCOPE = 'scheduling_appointments';
    private const SURFACE = 'scheduling_appointments';

    public function moduleKey(): string
    {
        return 'scheduling';
    }

    public function moduleLabel(): string
    {
        return 'Scheduling';
    }

    public function ownedScopes(): array
    {
        return [self::SCOPE];
    }

    public function contributions(): iterable
    {
        $steps = config('scheduling.communications.default_steps', []);

        if (! is_array($steps) || $steps === []) {
            return;
        }

        foreach (['email', 'sms'] as $channel) {
            $definitions = $this->definitionsForChannel($channel, $steps);

            if ($definitions === []) {
                continue;
            }

            yield new MessageTemplateDefinitionContribution(
                channel: $channel,
                purpose: self::PURPOSE,
                scope: self::SCOPE,
                definitions: $definitions,
                sourceConfigPath: 'scheduling.communications.default_steps',
                surface: self::SURFACE,
            );
        }
    }

    /**
     * @param array<int, mixed> $steps
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function definitionsForChannel(string $channel, array $steps): array
    {
        $grouped = [];
        $subject = trim((string) config(
            'scheduling.communications.default_subject',
            'Appointment reminder',
        ));
        $message = (string) config(
            'scheduling.communications.default_message',
            "Hello {first_name}! You have an appointment on:\n\n{appointment_date} at {appointment_time_with_timezone}.\n\n{appointment_location_or_method}\n\nThank you!",
        );
        $chainKey = trim((string) config(
            'scheduling.communications.chain_key',
            'scheduling_appointment_communications',
        ));
        $chainKey = $chainKey !== '' ? $chainKey : 'scheduling_appointment_communications';

        foreach ($steps as $stepIndex => $step) {
            if (! is_array($step)) {
                continue;
            }

            $key = $this->normalize((string) ($step['key'] ?? ''));
            $timing = $this->normalize((string) ($step['timing'] ?? ''));
            $name = is_string($step['name'] ?? null) ? trim($step['name']) : '';

            if ($key === '' || $name === '' || ! in_array($timing, ['immediate', 'before', 'after'], true)) {
                continue;
            }

            $stepSubject = is_string($step['subject'] ?? null)
                && trim($step['subject']) !== ''
                    ? trim($step['subject'])
                    : $subject;
            $stepMessage = is_string($step['message'] ?? null)
                && trim($step['message']) !== ''
                    ? $step['message']
                    : $message;
            $group = match ($timing) {
                'immediate' => 'confirmations',
                'after' => 'follow_ups',
                default => 'reminders',
            };
            $queue = match ($timing) {
                'immediate' => 'confirmation_messages',
                'after' => 'post_event',
                default => 'reminders',
            };
            $payload = $channel === 'email'
                ? [
                    'subject' => $stepSubject !== '' ? $stepSubject : 'Appointment reminder',
                    'body' => $stepMessage,
                ]
                : [
                    'message' => $stepMessage,
                ];

            $grouped[$group][] = [
                'key' => $key,
                'preset_key' => implode('_', [$chainKey, $key, $channel]),
                'dispatch_key' => self::DISPATCH_KEY,
                'payload_class' => $channel === 'email'
                    ? EmailPayload::class
                    : SmsPayload::class,
                'queue' => $queue,
                'payload' => $payload,
                'catalog' => [
                    'group_key' => 'scheduling:appointment_communications',
                    'group_label' => 'Appointment Communications',
                    'item_label' => $name.' · '.($channel === 'email' ? 'Email' : 'SMS'),
                    'item_order' => (((int) $stepIndex + 1) * 100) + ($channel === 'email' ? 10 : 20),
                    'usage_type' => 'scheduling_appointment_communication',
                ],
                'meta' => [
                    'scheduling' => [
                        'appointment_communications' => true,
                        'chain_key' => $chainKey,
                        'step_key' => $key,
                    ],
                ],
            ];
        }

        return $grouped;
    }

    private function normalize(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}