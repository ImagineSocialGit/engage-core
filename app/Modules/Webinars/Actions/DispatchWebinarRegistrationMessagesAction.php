<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\BuildConsentOptInMessageIntentAction;
use App\Modules\Messaging\Actions\DispatchMessageIntentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;

class DispatchWebinarRegistrationMessagesAction
{
    private const SCOPE = 'webinar';
    private const SURFACE = 'webinar_registrations';

    public function __construct(
        private readonly DispatchMessageIntentsAction $dispatchMessageIntents,
        private readonly BuildConsentOptInMessageIntentAction $buildConsentOptInIntent,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly StartWebinarMessageChainEnrollmentAction $startMessageChainEnrollment,
    ) {}

    /**
     * Confirmation and consent acknowledgements remain on the direct intent path
     * until component-aware consolidation is cut over. Reminders are enrolled in
     * the immutable registration chain and materialized only when due.
     *
     * @param array<int, string>|null $contextKeys
     * @param array<int, MessageConsentGrantResult> $consentGrants
     * @return array<int, ScheduledMessage>
     */
    public function handle(
        WebinarRegistration $registration,
        ?array $contextKeys = null,
        array $consentGrants = [],
    ): array {
        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        if (! $registration->contact || ! $registration->webinar) {
            return [];
        }

        $contextKeys = $this->normalizeContextKeys($contextKeys);
        $channels = $this->availableTransactionalChannels($registration);
        $messages = [];

        if ($this->includesConfirmationContext($contextKeys)
            || $this->includesInitialRegistrationContext($contextKeys)
        ) {
            $messageData = WebinarMessageData::fromRegistration($registration)->toArray();
            $payload = $this->messagePayload($messageData);
            $intents = $this->confirmationIntents(
                registration: $registration,
                channels: $channels,
                payload: $payload,
            );

            if ($this->includesInitialRegistrationContext($contextKeys)) {
                foreach ($consentGrants as $grant) {
                    if (! $grant instanceof MessageConsentGrantResult || ! $grant->becameActive) {
                        continue;
                    }

                    $intent = $this->buildConsentOptInIntent->handle(
                        contact: $registration->contact,
                        grant: $grant,
                        payload: $payload,
                        context: $registration,
                        resolverContext: [
                            'webinar_slug' => $registration->webinar_slug,
                        ],
                    );

                    if ($intent instanceof MessageDeliveryIntent) {
                        $intents[] = $intent;
                    }
                }
            }

            $messages = $this->dispatchMessageIntents->handle(
                intents: $intents,
                policyKey: 'webinar_registration',
            );
        }

        if (
            $channels !== []
            && $this->includesReminderContext($contextKeys)
            && $this->messageAreaRegistry->isEnabled('reminders')
        ) {
            $this->startMessageChainEnrollment->handle(
                webinar: $registration->webinar,
                messageAreaKey: 'reminders',
                recipient: $registration->contact,
                context: $registration,
                startedAt: $registration->registered_at ?? now(),
                required: false,
            );
        }

        return $messages;
    }

    /**
     * @param array<int, MessageChannel> $channels
     * @param array<string, mixed> $payload
     * @return array<int, MessageDeliveryIntent>
     */
    private function confirmationIntents(
        WebinarRegistration $registration,
        array $channels,
        array $payload,
    ): array {
        if (! $this->messageAreaRegistry->isEnabled('confirmation')) {
            return [];
        }

        $intents = [];

        foreach ($channels as $channel) {
            if (! $this->messageEligibilityGate->allows(
                contact: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
            )) {
                continue;
            }

            $definitions = $this->scheduleProfileDefinitionResolver->applyForWebinar(
                webinar: $registration->webinar,
                definitions: $this->messageDefinitionResolver->resolve(
                    channel: $channel,
                    purpose: MessagePurpose::Transactional->value,
                    scope: self::SCOPE,
                ),
                dispatchKeys: 'registration_created',
                surface: self::SURFACE,
            );

            $definitions = $this->messageAreaRegistry->filterDefinitions(
                definitions: $definitions,
                areaKeys: ['confirmation'],
                surface: self::SURFACE,
            );

            foreach ($definitions as $definition) {
                $definitionKey = $this->definitionKey($definition);

                $intents[] = MessageDeliveryIntent::fromDefinition(
                    key: 'webinar.registration.'.$definitionKey,
                    recipient: $registration->contact,
                    definition: $definition,
                    payload: $payload,
                    context: $registration,
                    triggeredAt: $registration->registered_at ?? now(),
                    anchor: $registration->webinar?->starts_at,
                    occurrenceKey: 'webinar_registration:'.$registration->getKey(),
                    meta: [
                        'delivery_intent' => [
                            'key' => 'webinar.registration.'.$definitionKey,
                            'consent_ids' => [],
                        ],
                        'webinar_schedule_profile_applied' => true,
                        'webinar_registration_id' => $registration->getKey(),
                        'webinar_id' => $registration->webinar_id,
                        'webinar_slug' => $registration->webinar_slug,
                    ],
                );
            }
        }

        return $intents;
    }

    /**
     * @param array<string, mixed> $messageData
     * @return array<string, mixed>
     */
    private function messagePayload(array $messageData): array
    {
        return [
            'tokens' => $messageData,
            'context' => [
                'contact' => $messageData['contact'] ?? [],
                'webinar_registration' => $messageData['webinar_registration'] ?? [],
                'webinar' => $messageData['webinar'] ?? [],
                'webinar_series' => $messageData['webinar_series'] ?? [],
            ],
        ];
    }

    /**
     * @return array<int, MessageChannel>
     */
    private function availableTransactionalChannels(
        WebinarRegistration $registration,
    ): array {
        $channels = $this->messageChannelAvailability->visibleChannelsForSurface(
            surface: self::SURFACE,
            purpose: MessagePurpose::Transactional->value,
            scope: self::SCOPE,
        );

        $acceptedChannels = $registration->meta['accepted_channels']['transactional'] ?? null;

        if (is_array($acceptedChannels)) {
            $channels = array_values(array_intersect($channels, $acceptedChannels));
        }

        return collect($channels)
            ->map(fn (string $channel): ?MessageChannel => MessageChannel::tryFrom($channel))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string>|null $contextKeys
     */
    private function includesConfirmationContext(?array $contextKeys): bool
    {
        return $contextKeys === null
            || in_array('confirmation', $contextKeys, true);
    }

    /**
     * @param array<int, string>|null $contextKeys
     */
    private function includesReminderContext(?array $contextKeys): bool
    {
        return $contextKeys === null
            || in_array('reminders', $contextKeys, true);
    }

    /**
     * @param array<int, string>|null $contextKeys
     */
    private function includesInitialRegistrationContext(?array $contextKeys): bool
    {
        if (! $this->messageAreaRegistry->isEnabled('registration_opt_in')) {
            return false;
        }

        return $this->includesConfirmationContext($contextKeys);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionKey(array $definition): string
    {
        foreach ([
            $definition['definition_key'] ?? null,
            $definition['key'] ?? null,
            data_get($definition, 'meta.message_template_assignment.definition_key'),
            $definition['message_type'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->normalizeSegment($candidate);
            }
        }

        return 'message';
    }

    /**
     * @param array<int, string>|null $contextKeys
     * @return array<int, string>|null
     */
    private function normalizeContextKeys(?array $contextKeys): ?array
    {
        return $this->messageAreaRegistry->normalizeEnabledAreaKeys($contextKeys);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}