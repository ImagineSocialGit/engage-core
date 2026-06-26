<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Messaging\Services\ConditionChecker;

class DispatchWebinarOutcomeMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly ConditionChecker $conditionChecker,
    ) {}

    public function handle(WebinarRegistration $registration, ?string $event = null): void
    {
        if (! config('webinars.post_event.outcome_messages.enabled', true)) {
            return;
        }

        $dispatchKey = config('webinars.post_event.outcome_messages.dispatch_key');

        if (! is_string($dispatchKey) || $dispatchKey === '') {
            return;
        }

        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        if (! $registration->contact || ! $registration->webinar) {
            return;
        }

        $route = $this->matchingRoute($registration, $event);

        if ($route === null) {
            return;
        }

        $messageData = WebinarMessageData::fromRegistration($registration)->toArray();

        $payload = [
            'tokens' => $messageData,
            'context' => [
                'contact' => $registration->contact->toArray(),
                'webinar_registration' => $registration->toArray(),
                'registration' => $registration->toArray(),
                'webinar' => $registration->webinar->toArray(),
                'webinar_series' => $registration->webinar->webinarSeries?->toArray() ?? [],
                'event' => [
                    'name' => $event,
                ],
            ],
        ];

        $meta = [
            'webinar_registration_id' => $registration->getKey(),
            'webinar_id' => $registration->webinar_id,
            'webinar_slug' => $registration->webinar_slug,
            'webinar_outcome' => $route,
            'post_event' => [
                'event' => $event,
                'route' => $route,
            ],
        ];

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $this->dispatchMessageAction->handle(
                recipient: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
                dispatchKeys: $dispatchKey,
                payload: $payload,
                context: $registration,
                triggeredAt: now(),
                anchor: $registration->webinar->ends_at,
                meta: $meta,
            );
        }
    }

    private function matchingRoute(WebinarRegistration $registration, ?string $event): ?string
    {
        $routes = config('webinars.post_event.outcome_messages.routes', []);

        if (! is_array($routes) || $routes === []) {
            return null;
        }

        $context = $this->conditionContext($registration, $event);

        foreach ($routes as $route => $config) {
            if (! is_string($route) || ! is_array($config)) {
                continue;
            }

            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            $conditions = $config['conditions'] ?? [];

            if (! is_array($conditions)) {
                continue;
            }

            if ($this->conditionChecker->passes($conditions, $context)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionContext(WebinarRegistration $registration, ?string $event): array
    {
        return [
            'event' => [
                'name' => $event,
            ],
            'contact' => $registration->contact?->toArray() ?? [],
            'registration' => $registration->toArray(),
            'webinar_registration' => $registration->toArray(),
            'webinar' => $registration->webinar?->toArray() ?? [],
            'webinar_series' => $registration->webinar?->webinarSeries?->toArray() ?? [],
        ];
    }
}