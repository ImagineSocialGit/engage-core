<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use BadMethodCallException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WebinarDevController extends Controller
{
    private const SCOPE = 'webinar';
    private const SURFACE = 'webinar_registrations';

    public function messageOptions(
        WebinarRegistration $registration,
        MessageDefinitionResolver $messageDefinitionResolver,
        MessageChannelAvailability $messageChannelAvailability,
    ): JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        return response()->json([
            'registration' => [
                'id' => $registration->getKey(),
                'status' => $registration->status,
                'contact_id' => $registration->contact_id,
                'webinar_id' => $registration->webinar_id,
                'webinar_slug' => $registration->webinar_slug,
            ],
            'messages' => $this->availableDefinitions(
                registration: $registration,
                messageDefinitionResolver: $messageDefinitionResolver,
                messageChannelAvailability: $messageChannelAvailability,
            ),
        ]);
    }

    public function sendRegistrationMessageNow(
        WebinarRegistration $registration,
        Request $request,
        DispatchMessageAction $dispatchMessageAction,
        MessageDefinitionResolver $messageDefinitionResolver,
        MessageChannelAvailability $messageChannelAvailability,
        MessageEligibilityGate $messageEligibilityGate,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $configPath = trim((string) $request->input('config_path'));

        if ($configPath === '') {
            return $this->failure($request, 'Choose a webinar message definition to send.');
        }

        $definitions = $this->definitionsForConfigPaths(
            registration: $registration,
            configPaths: [$configPath],
            messageDefinitionResolver: $messageDefinitionResolver,
            messageChannelAvailability: $messageChannelAvailability,
        );

        if ($definitions === []) {
            return $this->failure($request, 'No matching webinar message definition was found for dev send.');
        }

        $created = $this->dispatchDefinitionsNow(
            registration: $registration,
            definitions: $definitions,
            dispatchMessageAction: $dispatchMessageAction,
            messageEligibilityGate: $messageEligibilityGate,
        );

        return $this->success(
            request: $request,
            message: "Dev message send queued {$created} scheduled message(s).",
            data: ['scheduled_messages_created' => $created],
        );
    }

    public function sendAllRegistrationMessagesNow(
        WebinarRegistration $registration,
        Request $request,
        DispatchMessageAction $dispatchMessageAction,
        MessageDefinitionResolver $messageDefinitionResolver,
        MessageChannelAvailability $messageChannelAvailability,
        MessageEligibilityGate $messageEligibilityGate,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $definitions = collect($this->availableDefinitions(
            registration: $registration,
            messageDefinitionResolver: $messageDefinitionResolver,
            messageChannelAvailability: $messageChannelAvailability,
        ))
            ->flatMap(fn (array $group): array => $group['definitions'] ?? [])
            ->all();

        if ($definitions === []) {
            return $this->failure($request, 'No available webinar registration messages were found.');
        }

        $created = $this->dispatchDefinitionsNow(
            registration: $registration,
            definitions: $definitions,
            dispatchMessageAction: $dispatchMessageAction,
            messageEligibilityGate: $messageEligibilityGate,
        );

        return $this->success(
            request: $request,
            message: "Dev message send queued {$created} scheduled message(s).",
            data: ['scheduled_messages_created' => $created],
        );
    }

    public function simulateJoin(
        WebinarRegistration $registration,
        Request $request,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $destination = $resolveWebinarJoinUrlAction->execute($registration);

        if (blank($destination)) {
            return $this->failure($request, 'No join URL is available for this registration.');
        }

        return $this->success(
            request: $request,
            message: 'Dev join simulated. Join-click metadata was recorded and eligible live reminders were skipped.',
            data: [
                'join_url' => $destination,
                'registration_id' => $registration->getKey(),
            ],
        );
    }

    public function markRegistrationAttended(
        WebinarRegistration $registration,
        Request $request,
        EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        if (! $registration->contact_id) {
            return $this->failure($request, 'Dev event skipped because the registration has no contact.');
        }

        $attendedAt = now();
        $meta = $registration->meta ?? [];
        $meta['attendance'] = [
            'provider' => 'dev_testing',
            'status' => 'attended',
            'duration' => 1800,
            'join_time' => $attendedAt->toIso8601String(),
            'leave_time' => $attendedAt->copy()->addMinutes(30)->toIso8601String(),
            'recorded_at' => now()->toIso8601String(),
            'raw' => [
                'source' => 'crm_webinar_dev_controller',
            ],
        ];

        $registration->forceFill([
            'status' => 'attended',
            'attended_at' => $attendedAt,
            'meta' => $meta,
        ])->save();

        $emitWebinarAutomationEvent->forRegistration(
            eventKey: config('webinars.post_event.automation_events.attended.event_key', 'webinar.attended'),
            registration: $registration->fresh(['contact', 'webinar', 'webinar.webinarSeries']) ?? $registration,
            occurredAt: $attendedAt,
            payload: [
                'attendance' => [
                    'provider' => 'dev_testing',
                    'status' => 'attended',
                    'duration' => 1800,
                    'join_time' => $attendedAt->toIso8601String(),
                    'leave_time' => $attendedAt->copy()->addMinutes(30)->toIso8601String(),
                ],
            ],
            meta: [
                'source' => 'crm_webinar_dev_controller',
            ],
        );

        return $this->success($request, 'Dev webinar.attended event emitted.', [
            'registration_id' => $registration->getKey(),
            'status' => 'attended',
        ]);
    }

    public function markRegistrationMissed(
        WebinarRegistration $registration,
        Request $request,
        EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        if (! $registration->contact_id) {
            return $this->failure($request, 'Dev event skipped because the registration has no contact.');
        }

        $recordedAt = now();
        $meta = $registration->meta ?? [];
        $meta['attendance'] = [
            'provider' => 'dev_testing',
            'status' => 'missed',
            'recorded_at' => $recordedAt->toIso8601String(),
            'raw' => [
                'source' => 'crm_webinar_dev_controller',
            ],
        ];

        $registration->forceFill([
            'status' => 'missed',
            'attended_at' => null,
            'meta' => $meta,
        ])->save();

        $emitWebinarAutomationEvent->forRegistration(
            eventKey: config('webinars.post_event.automation_events.missed.event_key', 'webinar.missed'),
            registration: $registration->fresh(['contact', 'webinar', 'webinar.webinarSeries']) ?? $registration,
            occurredAt: $recordedAt,
            payload: [
                'attendance' => [
                    'provider' => 'dev_testing',
                    'status' => 'missed',
                ],
            ],
            meta: [
                'source' => 'crm_webinar_dev_controller',
            ],
        );

        return $this->success($request, 'Dev webinar.missed event emitted.', [
            'registration_id' => $registration->getKey(),
            'status' => 'missed',
        ]);
    }

    public function resetRegistration(WebinarRegistration $registration, Request $request): RedirectResponse|JsonResponse
    {
        $this->abortUnlessDevTestingAllowed();

        $meta = $registration->meta ?? [];
        unset($meta['attendance']);

        $registration->forceFill([
            'status' => 'pending',
            'attended_at' => null,
            'meta' => $meta,
        ])->save();

        return $this->success($request, 'Dev attendance state reset.', [
            'registration_id' => $registration->getKey(),
            'status' => 'pending',
        ]);
    }

    public function setReplayUrl(Webinar $webinar, Request $request): RedirectResponse|JsonResponse
    {
        $this->abortUnlessDevTestingAllowed();

        $webinar->forceFill([
            'playback_token' => $webinar->playback_token ?: Str::random(48),
            'playback_url' => url('/dev-testing/webinars/'.$webinar->getKey().'/replay'),
            'playback_passcode' => 'dev',
            'meta' => array_replace_recursive($webinar->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'playback_resolved_at' => now()->toIso8601String(),
                    ],
                ],
                'dev_testing' => [
                    'playback_url_set_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        return $this->success($request, 'Dev replay URL set.', [
            'webinar_id' => $webinar->getKey(),
            'playback_url' => $webinar->playback_url,
        ]);
    }

    public function clearReplayUrl(Webinar $webinar, Request $request): RedirectResponse|JsonResponse
    {
        $this->abortUnlessDevTestingAllowed();

        $webinar->forceFill([
            'playback_url' => null,
            'playback_passcode' => null,
        ])->save();

        return $this->success($request, 'Dev replay URL cleared.', [
            'webinar_id' => $webinar->getKey(),
            'playback_url' => null,
        ]);
    }

    public function dispatchFollowUps(
        Webinar $webinar,
        Request $request,
        DispatchPostWebinarFollowUpsAction $dispatchPostWebinarFollowUpsAction,
    ): RedirectResponse|JsonResponse {
        $this->abortUnlessDevTestingAllowed();

        $webinar = $webinar->fresh(['webinarSeries']) ?? $webinar;

        if (! filled($webinar->playback_url)) {
            return $this->failure($request, 'Set a dev replay URL before dispatching follow-ups.');
        }

        $meta = $webinar->meta ?? [];
        data_forget($meta, [
            'normalized.post_event.follow_ups_dispatched_at',
            'automation_events.webinar_ended_recorded_at',
        ]);

        $webinar->forceFill([
            'meta' => $meta,
        ])->save();

        $dispatched = $dispatchPostWebinarFollowUpsAction->execute(
            provider: $this->devProvider(),
            webinar: $webinar->fresh(['webinarSeries']) ?? $webinar,
            event: 'webinar.recording_completed',
        );

        if (! $dispatched) {
            return $this->failure($request, 'Dev post-webinar follow-ups did not dispatch. Check post-event conditions.');
        }

        return $this->success($request, 'Dev post-webinar follow-ups dispatched.', [
            'webinar_id' => $webinar->getKey(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableDefinitions(
        WebinarRegistration $registration,
        MessageDefinitionResolver $messageDefinitionResolver,
        MessageChannelAvailability $messageChannelAvailability,
    ): array {
        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        if (! $registration->contact) {
            return [];
        }

        $acceptedChannels = $registration->meta['accepted_channels']['transactional'] ?? null;
        $visibleChannels = $messageChannelAvailability->visibleChannelsForSurface(
            surface: self::SURFACE,
            purpose: MessagePurpose::Transactional->value,
            scope: self::SCOPE,
        );

        if (is_array($acceptedChannels)) {
            $visibleChannels = array_values(array_intersect($visibleChannels, $acceptedChannels));
        }

        $groups = [];

        foreach ($visibleChannels as $channel) {
            $channel = MessageChannel::tryFrom((string) $channel);

            if (! $channel) {
                continue;
            }

            $definitions = $messageDefinitionResolver->resolve(
                channel: $channel,
                purpose: MessagePurpose::Transactional->value,
                scope: self::SCOPE,
            );

            $definitions = collect($definitions)
                ->filter(fn (array $definition): bool => in_array('registration_created', $definition['dispatch_keys'] ?? [], true))
                ->map(fn (array $definition): array => $this->definitionOption($definition))
                ->values()
                ->all();

            if ($definitions === []) {
                continue;
            }

            $groups[] = [
                'channel' => $channel->value,
                'definitions' => $definitions,
            ];
        }

        return $groups;
    }

    /**
     * @param array<int, string> $configPaths
     * @return array<int, array<string, mixed>>
     */
    private function definitionsForConfigPaths(
        WebinarRegistration $registration,
        array $configPaths,
        MessageDefinitionResolver $messageDefinitionResolver,
        MessageChannelAvailability $messageChannelAvailability,
    ): array {
        $configPaths = array_values(array_unique(array_filter(array_map(
            fn (mixed $path): ?string => is_string($path) && trim($path) !== ''
                ? trim($path)
                : null,
            $configPaths,
        ))));

        if ($configPaths === []) {
            return [];
        }

        return collect($this->availableDefinitions(
            registration: $registration,
            messageDefinitionResolver: $messageDefinitionResolver,
            messageChannelAvailability: $messageChannelAvailability,
        ))
            ->flatMap(fn (array $group): array => $group['definitions'] ?? [])
            ->filter(fn (array $definition): bool => in_array($definition['config_path'] ?? null, $configPaths, true))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    private function dispatchDefinitionsNow(
        WebinarRegistration $registration,
        array $definitions,
        DispatchMessageAction $dispatchMessageAction,
        MessageEligibilityGate $messageEligibilityGate,
    ): int {
        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        if (! $registration->contact) {
            return 0;
        }

        $messageData = WebinarMessageData::fromRegistration($registration)->toArray();
        $created = 0;

        foreach ($definitions as $definition) {
            $channel = MessageChannel::tryFrom((string) ($definition['channel'] ?? ''));

            if (! $channel) {
                continue;
            }

            if (! $messageEligibilityGate->allows(
                contact: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
            )) {
                continue;
            }

            $inlineDefinition = $this->immediateDefinition($definition);

            $messages = $dispatchMessageAction->handle(
                recipient: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
                dispatchKeys: $inlineDefinition['dispatch_keys'] ?? [],
                payload: [
                    'tokens' => $messageData,
                    'context' => [
                        'contact' => $registration->contact->toArray(),
                        'webinar_registration' => $registration->toArray(),
                        'webinar' => $registration->webinar?->toArray() ?? [],
                        'webinar_series' => $registration->webinar?->webinarSeries?->toArray() ?? [],
                    ],
                ],
                context: $registration,
                triggeredAt: now(),
                anchor: now(),
                meta: [
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                    'dev_testing' => [
                        'source' => 'crm_webinar_dev_controller',
                        'forced_immediate' => true,
                        'original_config_path' => $definition['config_path'] ?? null,
                        'original_timing' => $definition['timing'] ?? null,
                        'original_schedule' => $definition['schedule'] ?? null,
                    ],
                ],
                definitions: [$inlineDefinition],
            );

            $created += count(array_filter(
                $messages,
                fn (ScheduledMessage $message): bool => $message->wasRecentlyCreated,
            ));
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function definitionOption(array $definition): array
    {
        $schedule = $definition['schedule'] ?? [];

        return [
            ...$definition,
            'label' => $this->definitionLabel($definition),
            'schedule_label' => $this->scheduleLabel(is_array($schedule) ? $schedule : []),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function immediateDefinition(array $definition): array
    {
        $dispatchKeys = $definition['dispatch_keys'] ?? [];

        return array_replace_recursive($definition, [
            'dispatch_key' => Arr::first($dispatchKeys),
            'dispatch_keys' => $dispatchKeys,
            'timing' => 'immediate',
            'schedule' => [
                'type' => 'delay',
                'minutes' => 0,
            ],
            'meta' => array_replace_recursive($definition['meta'] ?? [], [
                'dev_testing' => [
                    'forced_immediate' => true,
                    'original_timing' => $definition['timing'] ?? null,
                    'original_schedule' => $definition['schedule'] ?? null,
                ],
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionLabel(array $definition): string
    {
        $configPath = (string) ($definition['config_path'] ?? '');
        $messageType = (string) ($definition['message_type'] ?? 'message');
        $schedule = $definition['schedule'] ?? [];
        $scheduleLabel = $this->scheduleLabel(is_array($schedule) ? $schedule : []);

        if (Str::contains($configPath, '.confirmations')) {
            return trim('Confirmation '.$scheduleLabel);
        }

        if (Str::contains($configPath, '.reminders')) {
            return trim('Reminder '.$scheduleLabel);
        }

        if (Str::contains($configPath, '.opt_ins')) {
            return trim('Opt-in '.$scheduleLabel);
        }

        return trim(Str::headline($messageType).' '.$scheduleLabel);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function scheduleLabel(array $schedule): string
    {
        $type = $schedule['type'] ?? null;
        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            return '';
        }

        if ($type === 'delay') {
            return $minutes === 0
                ? 'immediate'
                : 'after '.$this->humanMinutes(abs($minutes));
        }

        if ($type === 'anchored') {
            if ($minutes === 0) {
                return 'at webinar start';
            }

            return $minutes < 0
                ? $this->humanMinutes(abs($minutes)).' before start'
                : $this->humanMinutes($minutes).' after start';
        }

        return '';
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = (int) ($minutes / 1440);

            return $days.' day'.($days === 1 ? '' : 's');
        }

        if ($minutes % 60 === 0) {
            $hours = (int) ($minutes / 60);

            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }

    private function devTestingAllowed(): bool
    {
        return app()->environment(['local', 'staging']);
    }

    private function abortUnlessDevTestingAllowed(): void
    {
        abort_unless($this->devTestingAllowed(), 404);
    }

    private function success(Request $request, string $message, array $data = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $data);
        }

        return back()->with('success', $message);
    }

    private function failure(Request $request, string $message, int $status = 422): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->with('error', $message);
    }

    private function devProvider(): WebinarProvider
    {
        return new class implements WebinarProvider {
            public function name(): string
            {
                return 'Dev Testing';
            }

            public function key(): string
            {
                return 'dev_testing';
            }

            public function listWebinarsByTitle(string $title): iterable
            {
                throw new BadMethodCallException('Dev provider does not list webinars.');
            }

            public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): ProviderRegistrationData
            {
                throw new BadMethodCallException('Dev provider does not register attendees.');
            }

            public function cancelRegistration(WebinarRegistration $registration): void
            {
                throw new BadMethodCallException('Dev provider does not cancel registrations.');
            }

            public function parseWebhook(Request $request): ProviderWebhookEvent
            {
                throw new BadMethodCallException('Dev provider does not parse webhooks.');
            }

            public function listAttendanceRecords(Webinar $webinar): iterable
            {
                return [];
            }

            public function getRecording(Webinar $webinar): ?ProviderRecordingData
            {
                return null;
            }
        };
    }
}
