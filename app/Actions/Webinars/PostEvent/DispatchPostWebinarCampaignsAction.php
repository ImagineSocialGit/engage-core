<?php

namespace App\Actions\Webinars\PostEvent;

use App\Actions\Campaigns\EnrollContactInCampaignAction;
use App\Contracts\Webinars\WebinarProvider;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ConditionChecker;

class DispatchPostWebinarCampaignsAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContactInCampaignAction,
        private readonly ConditionChecker $conditionChecker,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.campaigns.enabled', false)) {
            return true;
        }

        if (data_get($webinar->meta, 'normalized.post_event.campaigns_dispatched_at')) {
            return true;
        }

        $routes = config('webinars.post_event.campaigns.routes', []);

        if (! is_array($routes) || $routes === []) {
            return true;
        }

        $webinar->registrations()
            ->with([
                'contact',
                'webinar',
                'webinar.webinarSeries',
            ])
            ->get()
            ->each(function (WebinarRegistration $registration) use ($routes, $event) {
                $this->routeRegistration(
                    registration: $registration,
                    routes: $routes,
                    event: $event,
                );
            });

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'campaigns_dispatched_at' => now()->toIso8601String(),
                    ],
                ],
            ]),
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function routeRegistration(
        WebinarRegistration $registration,
        array $routes,
        string $event,
    ): void {
        if (! $registration->contact || ! $registration->webinar) {
            return;
        }

        $context = $this->conditionContext($registration, $event);

        foreach ($routes as $route => $config) {
            if (! is_string($route) || ! is_array($config)) {
                continue;
            }

            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            $campaignKey = $config['campaign_key'] ?? null;
            $dispatchKey = $config['dispatch_key'] ?? null;

            if (! is_string($campaignKey) || $campaignKey === '') {
                continue;
            }

            if (! is_string($dispatchKey) || $dispatchKey === '') {
                continue;
            }

            $conditions = $config['conditions'] ?? [];

            if (! is_array($conditions)) {
                continue;
            }

            if (! $this->conditionChecker->passes($conditions, $context)) {
                continue;
            }

            $this->enrollRegistration(
                registration: $registration,
                route: $route,
                campaignKey: $campaignKey,
                dispatchKey: $dispatchKey,
                event: $event,
            );
        }
    }

    private function enrollRegistration(
        WebinarRegistration $registration,
        string $route,
        string $campaignKey,
        string $dispatchKey,
        string $event,
    ): void {
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
            $this->enrollContactInCampaignAction->handle(
                contact: $registration->contact,
                campaignKey: $campaignKey,
                channel: $channel->value,
                purpose: MessagePurpose::Marketing->value,
                scope: self::SCOPE,
                dispatchKey: $dispatchKey,
                source: $registration,
                payload: $payload,
                meta: $meta,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionContext(WebinarRegistration $registration, string $event): array
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