<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\BuildConsentOptInMessageIntentAction;
use App\Modules\Messaging\Actions\DispatchMessageIntentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryComponent;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDeliveryConsolidator;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;

class DispatchWebinarRegistrationMessagesAction
{
    private const SCOPE = 'webinar';
    private const SURFACE = 'webinar_registrations';
    private const CONSOLIDATION_POLICY = 'webinar_registration';
    private const CONFIRMATION_INTENT = 'webinar.registration.confirmation';

    public function __construct(
        private readonly DispatchMessageIntentsAction $dispatchMessageIntents,
        private readonly BuildConsentOptInMessageIntentAction $buildConsentOptInIntent,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly MessageDeliveryConsolidator $deliveryConsolidator,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly StartWebinarMessageChainEnrollmentAction $startMessageChainEnrollment,
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

        if (! $registration->contact || ! $registration->webinar) {
            return [];
        }

        $contextKeys = $this->normalizeContextKeys($contextKeys);
        $channels = $this->availableTransactionalChannels($registration);
        $consentIntents = $this->includesInitialRegistrationContext($contextKeys)
            ? $this->consentIntents($registration, $consentGrants)
            : [];
        $components = $this->componentsForRegistrationChain(
            consentIntents: $consentIntents,
            channels: $channels,
        );
        $enrollment = $this->startLifecycleEnrollment(
            registration: $registration,
            contextKeys: $contextKeys,
            channels: $channels,
            components: $components,
        );
        $chainMessages = $enrollment instanceof MessageChainEnrollment
            ? $enrollment->scheduledMessages->values()->all()
            : [];
        $coveredIntentKeys = collect($chainMessages)
            ->flatMap(fn (ScheduledMessage $message) => $message->components)
            ->pluck('intent_key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();
        $standaloneIntents = array_values(array_filter(
            $consentIntents,
            fn (MessageDeliveryIntent $intent): bool => ! in_array(
                $this->normalizeSegment($intent->key),
                $coveredIntentKeys,
                true,
            ),
        ));
        $standaloneMessages = $this->dispatchMessageIntents->handle(
            intents: $standaloneIntents,
            policyKey: self::CONSOLIDATION_POLICY,
        );

        return collect([...$chainMessages, ...$standaloneMessages])
            ->unique(fn (ScheduledMessage $message): int => (int) $message->getKey())
            ->values()
            ->all();
    }

    /**
     * @param array<int, MessageConsentGrantResult> $consentGrants
     * @return array<int, MessageDeliveryIntent>
     */
    private function consentIntents(
        WebinarRegistration $registration,
        array $consentGrants,
    ): array {
        $messageData = WebinarMessageData::fromRegistration($registration)->toArray();
        $payload = [
            'tokens' => $messageData,
            'context' => [
                'contact' => $messageData['contact'] ?? [],
                'webinar_registration' => $messageData['webinar_registration'] ?? [],
                'webinar' => $messageData['webinar'] ?? [],
                'webinar_series' => $messageData['webinar_series'] ?? [],
            ],
        ];
        $intents = [];

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

        return $intents;
    }

    /**
     * @param array<int, MessageDeliveryIntent> $consentIntents
     * @param array<int, MessageChannel> $channels
     * @return array<int, MessageDeliveryComponent>
     */
    private function componentsForRegistrationChain(
        array $consentIntents,
        array $channels,
    ): array {
        $components = [];

        foreach ($channels as $channel) {
            $components = [
                ...$components,
                ...$this->deliveryConsolidator->componentsForCarrier(
                    memberIntents: $consentIntents,
                    policyKey: self::CONSOLIDATION_POLICY,
                    primaryIntentKey: self::CONFIRMATION_INTENT,
                    channel: $channel->value,
                ),
            ];
        }

        return $components;
    }

    /**
     * @param array<int, string>|null $contextKeys
     * @param array<int, MessageChannel> $channels
     * @param array<int, MessageDeliveryComponent> $components
     */
    private function startLifecycleEnrollment(
        WebinarRegistration $registration,
        ?array $contextKeys,
        array $channels,
        array $components,
    ): ?MessageChainEnrollment {
        if ($channels === []) {
            return null;
        }

        $messageAreaKey = null;

        if ($this->includesConfirmationContext($contextKeys)
            && $this->messageAreaRegistry->isEnabled('confirmation')
        ) {
            $messageAreaKey = 'confirmation';
        } elseif ($this->includesReminderContext($contextKeys)
            && $this->messageAreaRegistry->isEnabled('reminders')
        ) {
            $messageAreaKey = 'reminders';
        }

        if ($messageAreaKey === null) {
            return null;
        }

        return $this->startMessageChainEnrollment->handle(
            webinar: $registration->webinar,
            messageAreaKey: $messageAreaKey,
            recipient: $registration->contact,
            context: $registration,
            startedAt: $registration->registered_at ?? now(),
            required: false,
            components: $components,
        );
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

    /** @param array<int, string>|null $contextKeys */
    private function includesConfirmationContext(?array $contextKeys): bool
    {
        return $contextKeys === null
            || in_array('confirmation', $contextKeys, true);
    }

    /** @param array<int, string>|null $contextKeys */
    private function includesReminderContext(?array $contextKeys): bool
    {
        return $contextKeys === null
            || in_array('reminders', $contextKeys, true);
    }

    /** @param array<int, string>|null $contextKeys */
    private function includesInitialRegistrationContext(?array $contextKeys): bool
    {
        return $this->messageAreaRegistry->isEnabled('registration_opt_in')
            && $this->includesConfirmationContext($contextKeys);
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