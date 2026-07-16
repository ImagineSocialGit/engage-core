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
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;

class DispatchWebinarRegistrationMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageIntentsAction $dispatchMessageIntents,
        private readonly BuildConsentOptInMessageIntentAction $buildConsentOptInIntent,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ) {}

    /**
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

        if (! $registration->contact) {
            return [];
        }

        $contextKeys = $this->normalizeContextKeys($contextKeys);
        $messageData = WebinarMessageData::fromRegistration($registration)->toArray();
        $payload = $this->messagePayload($messageData);
        $intents = [];

        foreach ($this->availableTransactionalChannels($registration) as $channel) {
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
                surface: 'webinar_registrations',
            );

            foreach ($this->filterByContextKeys($definitions, $contextKeys) as $definition) {
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

        return $this->dispatchMessageIntents->handle(
            intents: $intents,
            policyKey: 'webinar_registration',
        );
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
    private function availableTransactionalChannels(WebinarRegistration $registration): array
    {
        $channels = $this->messageChannelAvailability->visibleChannelsForSurface(
            surface: 'webinar_registrations',
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
     * @param array<int, array<string, mixed>> $definitions
     * @param array<int, string>|null $contextKeys
     * @return array<int, array<string, mixed>>
     */
    private function filterByContextKeys(array $definitions, ?array $contextKeys): array
    {
        if ($contextKeys === null) {
            return $definitions;
        }

        if ($contextKeys === []) {
            return [];
        }

        return array_values(array_filter(
            $definitions,
            function (array $definition) use ($contextKeys): bool {
                $owner = $definition['behavior_owner'] ?? null;

                if (! $owner instanceof WebinarScheduleProfileItem) {
                    return false;
                }

                return in_array(
                    $this->normalizeSegment($owner->context_key),
                    $contextKeys,
                    true,
                );
            },
        ));
    }

    /**
     * @param array<int, string>|null $contextKeys
     */
    private function includesInitialRegistrationContext(?array $contextKeys): bool
    {
        if ($contextKeys === null) {
            return true;
        }

        return in_array('confirmation', $contextKeys, true)
            || in_array('confirmations', $contextKeys, true);
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
        if ($contextKeys === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $contextKey): ?string => is_string($contextKey) && trim($contextKey) !== ''
                ? $this->normalizeSegment($contextKey)
                : null,
            $contextKeys,
        ))));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
