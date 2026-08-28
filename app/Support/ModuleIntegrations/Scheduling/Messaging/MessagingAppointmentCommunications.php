<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CancelMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MessagingAppointmentCommunications implements AppointmentCommunications
{
    private const DISPATCH_CONTEXT = 'scheduling_appointment';
    private const PURPOSE = 'transactional';
    private const SCOPE = 'scheduling_appointments';
    private const SURFACE = 'scheduling_appointments';
    private const SOURCE = 'crm_scheduling';
    private const TEMPLATE_GROUP_KEY = 'scheduling:appointment_communications';
    private const TEMPLATE_GROUP_LABEL = 'Appointment Communications';
    private const TEMPLATE_USAGE_TYPE = 'scheduling_appointment_communication';

    public function __construct(
        private readonly PublishMessageTemplatePresetOverrideAction $publishTemplateOverride,
        private readonly PublishMessageChainVersionAction $publishChain,
        private readonly StartMessageChainEnrollmentAction $startEnrollment,
        private readonly CancelMessageChainEnrollmentAction $cancelEnrollment,
        private readonly GrantMessageConsentsAction $grantConsents,
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessageTemplateTokenValidator $tokenValidator,
        private readonly PhoneNumberNormalizer $phoneNumbers,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function plan(): array
    {
        $chain = $this->chain();

        return [
            'available' => true,
            'configured' => $chain instanceof MessageChain
                && $chain->isActive()
                && $chain->current_version_id !== null,
            'steps' => $chain instanceof MessageChain
                ? $this->presentedSteps($chain)
                : [],
            'channels' => $this->presentedChannels(),
            'tokens' => [
                '{first_name}',
                '{appointment_date}',
                '{appointment_time_with_timezone}',
                '{appointment_location_or_method}',
            ],
        ];
    }

    public function generateDefaultSchedule(?User $actor = null): array
    {
        return $this->saveSchedule(
            steps: $this->defaultSteps(),
            actor: $actor,
        );
    }

    public function saveSchedule(array $steps, ?User $actor = null): array
    {
        $steps = $this->normalizePlanSteps($steps);

        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => 'Keep at least one appointment communication or add a new one before saving.',
            ]);
        }

        DB::transaction(function () use ($steps, $actor): void {
            $chain = MessageChain::query()->firstOrNew([
                'key' => $this->chainKey(),
            ]);

            $chain->forceFill([
                'name' => 'Appointment Communications',
                'description' => 'Scheduling-owned appointment confirmations, reminders, scheduling updates, and appointment-related follow-up.',
                'status' => MessageChain::STATUS_ACTIVE,
                'source' => self::SOURCE,
                'source_version' => '23A3D',
                'is_customized' => true,
                'customized_at' => now(),
            ])->save();

            $compiled = [];
            $activeTemplateKeys = [];

            foreach ($steps as $sortOrder => $step) {
                $variants = [];

                foreach ($step['channels'] as $variantSort => $channel) {
                    $templateVersion = $this->publishStepTemplate(
                        step: $step,
                        channel: $channel,
                        itemOrder: (($sortOrder + 1) * 100) + (($variantSort + 1) * 10),
                        actor: $actor,
                    );
                    $activeTemplateKeys[] = $templateVersion->messageTemplate?->key
                        ?? $this->templateKey($step['key'], $channel);

                    $variants[] = [
                        'key' => $channel,
                        'sort_order' => $variantSort,
                        'message_template_version_id' => $templateVersion->getKey(),
                        'channel' => $channel,
                        'purpose' => self::PURPOSE,
                        'scope' => self::SCOPE,
                        'message_type' => $this->messageType($step),
                        'queue' => $this->queue($step),
                        'conditions' => [],
                        'is_active' => true,
                    ];
                }

                $compiled[] = [
                    'key' => $step['key'],
                    'name' => $step['name'],
                    'sort_order' => $sortOrder,
                    ...$this->timingDefinition($step),
                    'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
                    'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                    'conditions' => $step['timing'] === 'after'
                        ? [[
                            'field' => 'appointment.status',
                            'operator' => 'eq',
                            'value' => Appointment::STATUS_COMPLETED,
                        ]]
                        : [],
                    'is_active' => true,
                    'variants' => $variants,
                ];
            }

            $this->deactivateStaleAppointmentTemplates($activeTemplateKeys);

            $this->publishChain->handle(
                messageChain: $chain,
                steps: $compiled,
                createdBy: $actor,
            );
        }, 3);

        return $this->plan();
    }

    public function refreshChainForPublishedTemplate(
        MessageTemplatePreset $preset,
        MessageTemplateVersion $version,
        ?User $actor = null,
    ): void {
        if (! $this->ownsAppointmentTemplatePreset($preset)
            || (int) $version->message_template_id < 1
        ) {
            return;
        }

        $chain = $this->chain();

        if (! $chain instanceof MessageChain
            || ! $chain->isActive()
            || $chain->current_version_id === null
        ) {
            return;
        }

        $currentVersion = $chain->requireCurrentVersion();
        $currentVersion->load('steps.variants.messageTemplateVersion.messageTemplate');
        $steps = [];
        $changed = false;

        foreach ($currentVersion->steps as $step) {
            $definition = $step->definition();

            foreach ($step->variants as $index => $variant) {
                $templateKey = $variant->messageTemplateVersion?->messageTemplate?->key;

                if ($templateKey !== $preset->key) {
                    continue;
                }

                if ((int) $definition['variants'][$index]['message_template_version_id']
                    === (int) $version->getKey()
                ) {
                    continue;
                }

                $definition['variants'][$index]['message_template_version_id'] = $version->getKey();
                $changed = true;
            }

            $steps[] = $definition;
        }

        if (! $changed) {
            return;
        }

        $this->publishChain->handle(
            messageChain: $chain,
            steps: $steps,
            exitConditions: is_array($currentVersion->exit_conditions)
                ? $currentVersion->exit_conditions
                : [],
            createdBy: $actor,
        );
    }

    public function appointmentCreated(Appointment $appointment): void
    {
        if ($appointment->source === 'public_booking') {
            return;
        }

        $this->schedule($appointment);
    }

    public function publicBookingCompleted(
        Appointment $appointment,
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): void {
        $appointment->loadMissing(['contact', 'attendees']);

        $contact = $appointment->contact;

        if (! $contact instanceof Contact) {
            return;
        }

        $disclosure = data_get($appointment->meta, 'public_booking_disclosure');

        if (! is_array($disclosure)) {
            return;
        }

        $acceptedAt = $this->acceptedAt($disclosure['accepted_at'] ?? null);
        $grants = [];

        if (is_string($contact->email) && trim($contact->email) !== '') {
            $grants[] = $this->consentGrant(
                channel: MessageChannel::Email->value,
                acceptedAt: $acceptedAt,
                disclosure: $disclosure,
                appointment: $appointment,
                sourceIp: $sourceIp,
                userAgent: $userAgent,
            );
        }

        if ($this->smsDestinationMatchesAppointment($appointment, $contact)) {
            $grants[] = $this->consentGrant(
                channel: MessageChannel::Sms->value,
                acceptedAt: $acceptedAt,
                disclosure: $disclosure,
                appointment: $appointment,
                sourceIp: $sourceIp,
                userAgent: $userAgent,
            );
        }

        if ($grants !== []) {
            $this->grantConsents->handle(
                contact: $contact,
                grants: $grants,
                context: $appointment,
            );
        }

        $this->schedule($appointment);
    }

    public function appointmentRescheduled(
        Appointment $original,
        Appointment $replacement,
    ): void {
        $this->cancelForAppointment(
            appointment: $original,
            reason: 'appointment_rescheduled',
        );

        $this->schedule($replacement);
    }

    public function appointmentCancelled(Appointment $appointment): void
    {
        $this->cancelForAppointment(
            appointment: $appointment,
            reason: 'appointment_cancelled',
        );
    }

    public function appointmentCompleted(Appointment $appointment): void
    {
        $this->cancelForAppointment(
            appointment: $appointment,
            reason: 'appointment_completed',
        );

        $this->scheduleFollowUp($appointment);
    }

    public function appointmentNoShow(Appointment $appointment): void
    {
        $this->cancelForAppointment(
            appointment: $appointment,
            reason: 'appointment_no_show',
        );
    }

    public function appointmentStatus(Appointment $appointment): array
    {
        $configured = $this->plan()['configured'];

        if (! $configured) {
            return [
                'available' => true,
                'configured' => false,
                'enrollments' => [],
                'messages' => [],
            ];
        }

        $enrollments = $this->enrollmentsFor($appointment)
            ->load(['scheduledMessages'])
            ->sortByDesc('id')
            ->values();

        $messages = $enrollments
            ->flatMap(fn (MessageChainEnrollment $enrollment): Collection =>
                $enrollment->scheduledMessages->map(
                    fn ($message): array => [
                        'id' => (int) $message->getKey(),
                        'channel' => (string) $message->channel,
                        'message_type' => (string) $message->message_type,
                        'status' => (string) $message->status,
                        'send_at' => $message->send_at,
                    ],
                )
            )
            ->sortByDesc(fn (array $message): int =>
                $message['send_at']?->getTimestamp() ?? 0
            )
            ->values()
            ->all();

        return [
            'available' => true,
            'configured' => true,
            'enrollments' => $enrollments
                ->map(fn (MessageChainEnrollment $enrollment): array => [
                    'id' => (int) $enrollment->getKey(),
                    'status' => (string) $enrollment->status,
                    'next_action_at' => $enrollment->next_action_at,
                    'started_at' => $enrollment->started_at,
                    'exit_reason_code' => $enrollment->exit_reason_code,
                ])
                ->all(),
            'messages' => $messages,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultSteps(): array
    {
        $channels = $this->defaultChannels();
        $message = (string) config(
            'scheduling.communications.default_message',
            "Hello {first_name}! You have an appointment on:\n\n{appointment_date} at {appointment_time_with_timezone}.\n\n{appointment_location_or_method}\n\nThank you!",
        );
        $subject = (string) config(
            'scheduling.communications.default_subject',
            'Appointment reminder',
        );

        return [
            [
                'key' => 'confirmation',
                'name' => 'Appointment confirmation',
                'timing' => 'immediate',
                'offset_value' => null,
                'offset_unit' => null,
                'channels' => $channels,
                'subject' => $subject,
                'message' => $message,
            ],
            [
                'key' => 'reminder_3_days',
                'name' => '3-day reminder',
                'timing' => 'before',
                'offset_value' => 3,
                'offset_unit' => 'days',
                'channels' => $channels,
                'subject' => $subject,
                'message' => $message,
            ],
            [
                'key' => 'reminder_24_hours',
                'name' => '24-hour reminder',
                'timing' => 'before',
                'offset_value' => 24,
                'offset_unit' => 'hours',
                'channels' => $channels,
                'subject' => $subject,
                'message' => $message,
            ],
            [
                'key' => 'reminder_1_hour',
                'name' => '1-hour reminder',
                'timing' => 'before',
                'offset_value' => 1,
                'offset_unit' => 'hours',
                'channels' => $channels,
                'subject' => $subject,
                'message' => $message,
            ],
        ];
    }

    /** @return array<int, string> */
    private function defaultChannels(): array
    {
        $available = $this->channelAvailability->visibleChannelsForSurface(
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
            requireProvider: true,
        );

        if ($available !== []) {
            return $available;
        }

        $authorable = $this->channelAvailability->visibleChannelsForSurface(
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
            requireProvider: false,
        );

        if (in_array(MessageChannel::Email->value, $authorable, true)) {
            return [MessageChannel::Email->value];
        }

        return array_slice($authorable, 0, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizePlanSteps(array $steps): array
    {
        $normalized = [];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $timing = strtolower(trim((string) ($step['timing'] ?? '')));
            $channels = array_values(array_unique(array_filter(array_map(
                static fn (mixed $channel): ?string =>
                    is_string($channel) ? strtolower(trim($channel)) : null,
                is_array($step['channels'] ?? null) ? $step['channels'] : [],
            ))));

            $channels = $this->channelAvailability->normalizeVisibleChannelsForSurface(
                channels: $channels,
                surface: self::SURFACE,
                purpose: self::PURPOSE,
                scope: self::SCOPE,
                requireProvider: false,
            );

            if ($channels === []) {
                throw ValidationException::withMessages([
                    "steps.{$index}.channels" => 'Choose at least one available communication channel.',
                ]);
            }

            if (! in_array($timing, ['immediate', 'before', 'after'], true)) {
                throw ValidationException::withMessages([
                    "steps.{$index}.timing" => 'Choose when this appointment message should be sent.',
                ]);
            }

            $offsetValue = $timing === 'immediate'
                ? null
                : max(1, (int) ($step['offset_value'] ?? 0));
            $offsetUnit = $timing === 'immediate'
                ? null
                : strtolower(trim((string) ($step['offset_unit'] ?? '')));

            if ($timing !== 'immediate'
                && ! in_array($offsetUnit, ['minutes', 'hours', 'days'], true)
            ) {
                throw ValidationException::withMessages([
                    "steps.{$index}.offset_unit" => 'Choose minutes, hours, or days.',
                ]);
            }

            $message = trim((string) ($step['message'] ?? ''));

            if ($message === '') {
                throw ValidationException::withMessages([
                    "steps.{$index}.message" => 'Enter the appointment message.',
                ]);
            }

            if (mb_strlen($message) > 5000) {
                throw ValidationException::withMessages([
                    "steps.{$index}.message" => 'Appointment messages cannot exceed 5,000 characters.',
                ]);
            }

            $subject = trim((string) ($step['subject'] ?? ''));

            if (mb_strlen($subject) > 255) {
                throw ValidationException::withMessages([
                    "steps.{$index}.subject" => 'Email subjects cannot exceed 255 characters.',
                ]);
            }

            $key = $this->stepKey(
                is_string($step['key'] ?? null) ? $step['key'] : null,
            );
            $name = trim((string) ($step['name'] ?? ''));

            if ($name === '') {
                $name = $timing === 'immediate'
                    ? 'Appointment confirmation'
                    : Str::headline($key);
            }

            $normalized[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 80),
                'timing' => $timing,
                'offset_value' => $offsetValue,
                'offset_unit' => $offsetUnit,
                'channels' => $channels,
                'subject' => $subject !== '' ? $subject : 'Appointment reminder',
                'message' => $message,
            ];
        }

        return $this->sortPlanSteps($normalized);
    }

    /** @param array<string, mixed> $step */
    private function publishStepTemplate(
        array $step,
        string $channel,
        int $itemOrder,
        ?User $actor,
    ): MessageTemplateVersion {
        $payload = $channel === MessageChannel::Email->value
            ? [
                'subject' => $step['subject'],
                'body' => $step['message'],
            ]
            : [
                'message' => $step['message'],
            ];

        if (str_contains($step['message'], '{first_name}')
            || ($channel === MessageChannel::Email->value
                && str_contains($step['subject'], '{first_name}'))
        ) {
            $payload['token_fallbacks'] = [[
                'token' => 'first_name',
                'missing_behavior' => 'fallback_value',
                'fallback' => 'there',
            ]];
        } else {
            $payload['token_fallbacks'] = [];
        }

        $issues = $this->tokenValidator->validatePayload(
            payload: $payload,
            dispatchKeys: [self::DISPATCH_CONTEXT],
            channel: $channel,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
            surface: self::SURFACE,
        );
        $error = collect($issues)->first(
            fn (array $issue): bool => ($issue['level'] ?? null) === 'error',
        );

        if (is_array($error)) {
            throw ValidationException::withMessages([
                'steps' => (string) ($error['message'] ?? 'Appointment message contains an unavailable field.'),
            ]);
        }

        $templateKey = $this->templateKey($step['key'], $channel);
        $name = $step['name'].' · '.$this->channelLabel($channel);
        $preset = MessageTemplatePreset::query()->firstOrNew([
            'key' => $templateKey,
        ]);
        $sourcePayload = $preset->exists && is_array($preset->payload)
            ? $preset->payload
            : $payload;

        $preset->forceFill([
            'name' => $name,
            'description' => 'Appointment confirmation or reminder managed from Scheduling.',
            'channel' => $channel,
            'purpose' => self::PURPOSE,
            'scope' => self::SCOPE,
            'message_type' => $this->messageType($step),
            'payload_class' => $channel === MessageChannel::Email->value
                ? EmailPayload::class
                : SmsPayload::class,
            'queue' => $this->queue($step),
            'dispatch_keys' => [self::DISPATCH_CONTEXT],
            'payload' => $sourcePayload,
            'tokens' => $this->tokenValidator->tokensFromPayload($payload),
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
            'source' => self::SOURCE,
            'source_config_path' => null,
            'source_version' => 1,
            'is_customized' => true,
            'customized_at' => $preset->customized_at ?? now(),
            'last_synced_at' => null,
            'meta' => [
                'authoring' => [
                    'context_key' => self::DISPATCH_CONTEXT,
                    'selection_contexts' => [self::DISPATCH_CONTEXT],
                ],
                'scheduling' => [
                    'appointment_communications' => true,
                    'chain_key' => $this->chainKey(),
                    'step_key' => $step['key'],
                ],
            ],
        ])->save();

        $template = MessageTemplate::query()->firstOrNew([
            'key' => $templateKey,
        ]);

        $template->forceFill([
            'name' => $name,
            'description' => 'Scheduling-owned appointment communication.',
            'channel' => $channel,
            'status' => MessageTemplate::STATUS_ACTIVE,
            'composition_context_key' => null,
            'composition_family_key' => null,
            'source' => self::SOURCE,
            'source_version' => '23A3D',
            'is_customized' => true,
            'customized_at' => $template->customized_at ?? now(),
        ])->save();

        $catalogEntry = MessageTemplateCatalogEntry::query()->firstOrNew([
            'message_template_preset_id' => $preset->getKey(),
            'item_key' => $templateKey,
        ]);
        $catalogEntry->forceFill([
            'channel' => $channel,
            'purpose' => self::PURPOSE,
            'scope' => self::SCOPE,
            'module_key' => 'scheduling',
            'module_label' => 'Scheduling',
            'surface' => self::SURFACE,
            'group_key' => self::TEMPLATE_GROUP_KEY,
            'group_label' => self::TEMPLATE_GROUP_LABEL,
            'item_label' => $name,
            'item_order' => $itemOrder,
            'usage_type' => self::TEMPLATE_USAGE_TYPE,
            'source' => self::SOURCE,
            'source_config_path' => null,
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'meta' => [
                'scheduling' => [
                    'appointment_communications' => true,
                    'chain_key' => $this->chainKey(),
                    'step_key' => $step['key'],
                ],
            ],
        ])->save();

        $result = $this->publishTemplateOverride->handle(
            preset: $preset,
            submittedPayload: $payload,
            createdBy: $actor,
        );

        return $result->version->load('messageTemplate');
    }

    /** @param array<int, string> $activeTemplateKeys */
    private function deactivateStaleAppointmentTemplates(array $activeTemplateKeys): void
    {
        $activeTemplateKeys = array_values(array_unique($activeTemplateKeys));
        $query = MessageTemplatePreset::query()
            ->where('source', self::SOURCE)
            ->where('meta->scheduling->appointment_communications', true);

        if ($activeTemplateKeys !== []) {
            $query->whereNotIn('key', $activeTemplateKeys);
        }

        $stale = $query->get();

        foreach ($stale as $preset) {
            $preset->forceFill([
                'status' => MessageTemplatePreset::STATUS_INACTIVE,
                'is_active' => false,
            ])->save();

            $preset->catalogEntries()->update([
                'is_active' => false,
            ]);
        }
    }

    private function ownsAppointmentTemplatePreset(MessageTemplatePreset $preset): bool
    {
        return $preset->source === self::SOURCE
            && (bool) data_get($preset->meta, 'scheduling.appointment_communications', false)
            && data_get($preset->meta, 'scheduling.chain_key') === $this->chainKey();
    }

    private function templateKey(string $stepKey, string $channel): string
    {
        return implode('_', [
            $this->chainKey(),
            $stepKey,
            $channel,
        ]);
    }

    private function channelLabel(string $channel): string
    {
        return $channel === MessageChannel::Email->value ? 'Email' : 'SMS';
    }

    /** @param array<string, mixed> $step */
    private function timingDefinition(array $step): array
    {
        if ($step['timing'] === 'immediate') {
            return [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            ];
        }

        $seconds = $this->offsetSeconds(
            value: (int) $step['offset_value'],
            unit: (string) $step['offset_unit'],
        );

        return [
            'timing_type' => MessageChainStep::TIMING_ANCHORED,
            'anchor_key' => 'appointment.starts_at',
            'offset_seconds' => $step['timing'] === 'before'
                ? -$seconds
                : $seconds,
        ];
    }

    /** @param array<string, mixed> $step */
    private function messageType(array $step): string
    {
        return match ($step['timing']) {
            'immediate' => 'appointment_confirmation',
            'after' => 'appointment_follow_up',
            default => 'appointment_reminder',
        };
    }

    /** @param array<string, mixed> $step */
    private function queue(array $step): string
    {
        return match ($step['timing']) {
            'immediate' => 'confirmation_messages',
            'after' => 'post_event',
            default => 'reminders',
        };
    }

    private function schedule(Appointment $appointment): void
    {
        if (! in_array($appointment->status, [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_SCHEDULED,
            Appointment::STATUS_CONFIRMED,
        ], true)) {
            return;
        }

        $chain = $this->chain();

        if (! $chain instanceof MessageChain
            || ! $chain->isActive()
            || $chain->current_version_id === null
        ) {
            return;
        }

        $appointment->loadMissing('contact');
        $contact = $appointment->contact;

        if (! $contact instanceof Contact) {
            return;
        }

        $this->startEnrollment->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: $this->enrollmentDedupeKey($appointment),
            context: $appointment,
            origin: $appointment,
            surface: self::SURFACE,
        );
    }

    private function scheduleFollowUp(Appointment $appointment): void
    {
        if ($appointment->status !== Appointment::STATUS_COMPLETED) {
            return;
        }

        $chain = $this->chain();

        if (! $chain instanceof MessageChain
            || ! $chain->isActive()
            || $chain->current_version_id === null
        ) {
            return;
        }

        $version = $chain->currentVersion()
            ->with('steps')
            ->first();

        if ($version === null) {
            return;
        }

        $firstFollowUp = $version->steps
            ->filter(fn (MessageChainStep $step): bool =>
                (bool) $step->is_active
                && $step->timing_type === MessageChainStep::TIMING_ANCHORED
                && $step->anchor_key === 'appointment.starts_at'
                && (int) $step->offset_seconds > 0
            )
            ->sortBy(fn (MessageChainStep $step): array => [
                (int) $step->sort_order,
                (int) $step->getKey(),
            ])
            ->first();

        if (! $firstFollowUp instanceof MessageChainStep) {
            return;
        }

        $appointment->loadMissing('contact');
        $contact = $appointment->contact;

        if (! $contact instanceof Contact || $appointment->starts_at === null) {
            return;
        }

        $startedAt = now('UTC');
        $intendedAt = $appointment->starts_at
            ->copy()
            ->utc()
            ->addSeconds((int) $firstFollowUp->offset_seconds);
        $initialActionAt = $intendedAt->isFuture()
            ? $intendedAt
            : $startedAt;

        $this->startEnrollment->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: $this->followUpEnrollmentDedupeKey($appointment),
            context: $appointment,
            origin: $appointment,
            startedAt: $startedAt,
            surface: self::SURFACE,
            startStepKey: (string) $firstFollowUp->key,
            initialActionAt: $initialActionAt,
        );
    }

    private function cancelForAppointment(
        Appointment $appointment,
        string $reason,
    ): void {
        foreach ($this->enrollmentsFor($appointment) as $enrollment) {
            if (! $enrollment->isTerminal()) {
                $this->cancelEnrollment->handle(
                    enrollment: $enrollment,
                    reason: $reason,
                );
            }
        }
    }

    /** @return Collection<int, MessageChainEnrollment> */
    private function enrollmentsFor(Appointment $appointment): Collection
    {
        return MessageChainEnrollment::query()
            ->where('context_type', $appointment->getMorphClass())
            ->where('context_id', $appointment->getKey())
            ->where('surface', self::SURFACE)
            ->whereHas(
                'messageChainVersion.messageChain',
                fn ($query) => $query->where('key', $this->chainKey()),
            )
            ->get();
    }

    private function smsDestinationMatchesAppointment(
        Appointment $appointment,
        Contact $contact,
    ): bool {
        if (! $this->channelAvailability->isVisibleForSurface(
            channel: MessageChannel::Sms,
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
            requireProvider: false,
        )) {
            return false;
        }

        $contactPhone = $this->normalizedPhone($contact->phone);

        if ($contactPhone === null) {
            return false;
        }

        $attendee = $appointment->attendees
            ->first(fn (AppointmentAttendee $candidate): bool =>
                (int) ($candidate->contact_id ?? 0) === (int) $contact->getKey()
            )
            ?? $appointment->attendees->first();
        $appointmentPhone = $this->normalizedPhone($attendee?->phone);

        return $appointmentPhone !== null
            && hash_equals($contactPhone, $appointmentPhone);
    }

    private function normalizedPhone(?string $phone): ?string
    {
        try {
            return $this->phoneNumbers->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $disclosure
     * @return array<string, mixed>
     */
    private function consentGrant(
        string $channel,
        CarbonImmutable $acceptedAt,
        array $disclosure,
        Appointment $appointment,
        ?string $sourceIp,
        ?string $userAgent,
    ): array {
        return [
            'channel' => $channel,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => self::SCOPE,
            'source' => 'scheduling_public_booking',
            'consented_at' => $acceptedAt,
            'ip_address' => $sourceIp,
            'user_agent' => $userAgent,
            'meta' => [
                'capture_surface' => 'scheduling_public_booking',
                'appointment_id' => (int) $appointment->getKey(),
                'disclosure' => array_filter([
                    'key' => $disclosure['key'] ?? null,
                    'version' => $disclosure['version'] ?? null,
                    'text_hash' => $disclosure['text_hash'] ?? null,
                    'accepted_at' => $acceptedAt->toISOString(),
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];
    }

    private function acceptedAt(mixed $value): CarbonImmutable
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (\Throwable) {
                // Preserve a usable grant time even if imported legacy evidence is malformed.
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /** @return array<int, array<string, mixed>> */
    private function presentedChannels(): array
    {
        return collect(MessageChannel::cases())
            ->map(fn (MessageChannel $channel): array => [
                'key' => $channel->value,
                'label' => $channel === MessageChannel::Email ? 'Email' : 'SMS',
                'available' => $this->channelAvailability->isVisibleForSurface(
                    channel: $channel,
                    surface: self::SURFACE,
                    purpose: self::PURPOSE,
                    scope: self::SCOPE,
                    requireProvider: false,
                ),
                'provider_ready' => $this->channelAvailability->isVisibleForSurface(
                    channel: $channel,
                    surface: self::SURFACE,
                    purpose: self::PURPOSE,
                    scope: self::SCOPE,
                    requireProvider: true,
                ),
            ])
            ->filter(fn (array $channel): bool => $channel['available'])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function presentedSteps(MessageChain $chain): array
    {
        $version = $chain->currentVersion()
            ->with('steps.variants.messageTemplateVersion')
            ->first();

        if ($version === null) {
            return [];
        }

        return $version->steps
            ->filter(fn (MessageChainStep $step): bool => (bool) $step->is_active)
            ->map(function (MessageChainStep $step): array {
                $variants = $step->variants
                    ->filter(fn ($variant): bool => (bool) $variant->is_active)
                    ->values();
                $email = $variants->firstWhere('channel', MessageChannel::Email->value);
                $first = $email ?? $variants->first();
                $payload = $first?->messageTemplateVersion?->payload() ?? [];
                $message = is_string($payload['body'] ?? null)
                    ? $payload['body']
                    : (is_string($payload['message'] ?? null) ? $payload['message'] : '');
                $subject = is_string($payload['subject'] ?? null)
                    ? $payload['subject']
                    : 'Appointment reminder';

                [$timing, $offsetValue, $offsetUnit] = $this->presentTiming($step);

                return [
                    'key' => (string) $step->key,
                    'name' => (string) ($step->name ?: Str::headline((string) $step->key)),
                    'timing' => $timing,
                    'offset_value' => $offsetValue,
                    'offset_unit' => $offsetUnit,
                    'channels' => $variants->pluck('channel')->values()->all(),
                    'subject' => $subject,
                    'message' => $message,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{0: string, 1: int|null, 2: string|null} */
    private function presentTiming(MessageChainStep $step): array
    {
        if ($step->timing_type === MessageChainStep::TIMING_IMMEDIATE) {
            return ['immediate', null, null];
        }

        $seconds = (int) $step->offset_seconds;
        $timing = $seconds < 0 ? 'before' : 'after';
        $absolute = abs($seconds);

        if ($absolute === 86400) {
            return [$timing, 24, 'hours'];
        }

        if ($absolute > 0 && $absolute % 86400 === 0) {
            return [$timing, intdiv($absolute, 86400), 'days'];
        }

        if ($absolute > 0 && $absolute % 3600 === 0) {
            return [$timing, intdiv($absolute, 3600), 'hours'];
        }

        return [$timing, max(1, intdiv(max(60, $absolute), 60)), 'minutes'];
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function sortPlanSteps(array $steps): array
    {
        usort($steps, function (array $left, array $right): int {
            return $this->sortWeight($left) <=> $this->sortWeight($right);
        });

        return array_values($steps);
    }

    /** @param array<string, mixed> $step */
    private function sortWeight(array $step): array
    {
        if ($step['timing'] === 'immediate') {
            return [0, 0];
        }

        $seconds = $this->offsetSeconds(
            (int) $step['offset_value'],
            (string) $step['offset_unit'],
        );

        return $step['timing'] === 'before'
            ? [1, -$seconds]
            : [2, $seconds];
    }

    private function offsetSeconds(int $value, string $unit): int
    {
        return match ($unit) {
            'days' => $value * 86400,
            'hours' => $value * 3600,
            default => $value * 60,
        };
    }

    private function stepKey(?string $key): string
    {
        $key = is_string($key) ? Str::snake(trim($key)) : '';

        if ($key === '' || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            return 'message_'.Str::lower(Str::random(12));
        }

        return mb_substr($key, 0, 128);
    }

    private function chain(): ?MessageChain
    {
        return MessageChain::query()
            ->where('key', $this->chainKey())
            ->first();
    }

    private function chainKey(): string
    {
        $key = trim((string) config(
            'scheduling.communications.chain_key',
            'scheduling_appointment_communications',
        ));

        return $key !== '' ? $key : 'scheduling_appointment_communications';
    }

    private function enrollmentDedupeKey(Appointment $appointment): string
    {
        return 'scheduling:appointment:'.$appointment->getKey().':communications';
    }

    private function followUpEnrollmentDedupeKey(Appointment $appointment): string
    {
        return $this->enrollmentDedupeKey($appointment).':follow_up';
    }
}