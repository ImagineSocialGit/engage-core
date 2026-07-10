<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Support\Collection;
use Throwable;

class WebinarMessageReadinessService
{
    public const STATUS_READY = 'ready';
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';
    public const STATUS_OPTIONAL = 'optional';

    /**
     * @var array<string, array{
     *     label: string,
     *     purpose: string,
     *     scope: string,
     *     surface: string,
     *     message_type: string,
     *     dispatch_key: string,
     *     required: bool|string
     * }>
     */
    private const CONTEXTS = [
        'confirmation' => [
            'label' => 'Registration confirmations',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'required' => true,
        ],
        'registration_opt_in' => [
            'label' => 'Registration opt-in confirmations',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'required' => 'registration_messaging_available',
        ],
        'reminders' => [
            'label' => 'Reminder messages',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'reminder',
            'dispatch_key' => 'registration_created',
            'required' => true,
        ],
        'waitlist' => [
            'label' => 'Waitlist availability messages',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'surface' => 'webinar_waitlists',
            'message_type' => 'alert',
            'dispatch_key' => 'webinar_added',
            'required' => false,
        ],
        'waitlist_opt_in' => [
            'label' => 'Waitlist opt-in confirmations',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'surface' => 'webinar_waitlists',
            'message_type' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'required' => 'waitlist_messaging_available',
        ],
        'post_attended' => [
            'label' => 'Attended replay follow-up',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
            'required' => 'post_event_outcome_messages',
        ],
        'post_missed' => [
            'label' => 'Missed replay follow-up',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'post_missed',
            'dispatch_key' => 'webinar_ended',
            'required' => 'post_event_outcome_messages',
        ],
    ];

    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     summary: string,
     *     contexts: array<string, array<string, mixed>>,
     *     counts: array{ready: int, needs_attention: int, optional: int},
     *     profile_names: array<int, string>,
     *     issues: array<int, string>
     * }
     */
    public function resolve(): array
    {
        $profileState = $this->profilesInUse();
        $profiles = $profileState['profiles'];
        $issues = $profileState['issues'];

        $contexts = [];

        foreach (self::CONTEXTS as $contextKey => $definition) {
            $contexts[$contextKey] = $this->resolveContext(
                contextKey: $contextKey,
                definition: $definition,
                profiles: $profiles,
            );
        }

        $counts = [
            self::STATUS_READY => 0,
            self::STATUS_NEEDS_ATTENTION => 0,
            self::STATUS_OPTIONAL => 0,
        ];

        foreach ($contexts as $context) {
            $counts[$context['status']]++;
        }

        $status = $issues !== [] || $counts[self::STATUS_NEEDS_ATTENTION] > 0
            ? self::STATUS_NEEDS_ATTENTION
            : self::STATUS_READY;

        return [
            'status' => $status,
            'label' => $status === self::STATUS_READY
                ? 'Ready for webinar messaging'
                : 'Webinar messaging needs attention',
            'summary' => $this->overallSummary($status, $counts, $issues),
            'contexts' => $contexts,
            'counts' => [
                'ready' => $counts[self::STATUS_READY],
                'needs_attention' => $counts[self::STATUS_NEEDS_ATTENTION],
                'optional' => $counts[self::STATUS_OPTIONAL],
            ],
            'profile_names' => $profiles->pluck('name')->filter()->values()->all(),
            'issues' => $issues,
        ];
    }

    /**
     * @param array{
     *     label: string,
     *     purpose: string,
     *     scope: string,
     *     surface: string,
     *     message_type: string,
     *     dispatch_key: string,
     *     required: bool|string
     * } $definition
     * @param Collection<int, WebinarScheduleProfile> $profiles
     * @return array<string, mixed>
     */
    private function resolveContext(
        string $contextKey,
        array $definition,
        Collection $profiles,
    ): array {
        $required = $this->isRequired(
            rule: $definition['required'],
            definition: $definition,
        );
        $channels = $this->messageChannelAvailability->visibleChannelsForSurface(
            surface: $definition['surface'],
            purpose: $definition['purpose'],
            scope: $definition['scope'],
        );

        if ($channels === []) {
            return [
                'key' => $contextKey,
                'label' => $definition['label'],
                'status' => $required ? self::STATUS_NEEDS_ATTENTION : self::STATUS_OPTIONAL,
                'status_label' => $required ? 'Needs attention' : 'Optional / disabled',
                'summary' => $required
                    ? 'No Messaging channel is currently available for this required webinar message area.'
                    : 'This optional webinar message area is not currently available on any channel.',
                'required' => $required,
                'channels' => [],
            ];
        }

        $channelStates = [];

        foreach ($channels as $channel) {
            $channelStates[] = $this->resolveChannelState(
                channel: $channel,
                definition: $definition,
                profiles: $profiles,
            );
        }

        $blockingStates = array_values(array_filter(
            $channelStates,
            fn (array $state): bool => $state['status'] === self::STATUS_NEEDS_ATTENTION,
        ));
        $readyStates = array_values(array_filter(
            $channelStates,
            fn (array $state): bool => $state['status'] === self::STATUS_READY,
        ));
        $optionalStates = array_values(array_filter(
            $channelStates,
            fn (array $state): bool => $state['status'] === self::STATUS_OPTIONAL,
        ));

        if ($blockingStates !== []) {
            $status = $required ? self::STATUS_NEEDS_ATTENTION : self::STATUS_OPTIONAL;
        } elseif ($readyStates !== []) {
            $status = self::STATUS_READY;
        } else {
            $status = self::STATUS_OPTIONAL;
        }

        return [
            'key' => $contextKey,
            'label' => $definition['label'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'summary' => $this->contextSummary(
                required: $required,
                readyStates: $readyStates,
                blockingStates: $blockingStates,
                optionalStates: $optionalStates,
            ),
            'required' => $required,
            'channels' => $channelStates,
        ];
    }

    /**
     * @param array{
     *     label: string,
     *     purpose: string,
     *     scope: string,
     *     surface: string,
     *     message_type: string,
     *     dispatch_key: string,
     *     required: bool|string
     * } $definition
     * @param Collection<int, WebinarScheduleProfile> $profiles
     * @return array<string, mixed>
     */
    private function resolveChannelState(
        string $channel,
        array $definition,
        Collection $profiles,
    ): array {
        try {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $channel,
                purpose: $definition['purpose'],
                scope: $definition['scope'],
            );
        } catch (Throwable $exception) {
            return [
                'channel' => $channel,
                'status' => self::STATUS_NEEDS_ATTENTION,
                'status_label' => 'Needs attention',
                'summary' => 'Messaging definitions could not be resolved.',
                'source_labels' => [],
                'profiles_disabled' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $matchingDefinitions = $this->matchingDefinitions($definitions, $definition);

        if ($matchingDefinitions === []) {
            return [
                'channel' => $channel,
                'status' => self::STATUS_NEEDS_ATTENTION,
                'status_label' => 'Needs attention',
                'summary' => 'No compatible Messaging definition resolves for this channel.',
                'source_labels' => [],
                'profiles_disabled' => [],
                'error' => null,
            ];
        }

        $profilesDisabled = [];
        $profilesReady = [];

        foreach ($profiles as $profile) {
            $effectiveDefinitions = $this->scheduleProfileDefinitionResolver->applyProfile(
                profile: $profile,
                definitions: $definitions,
                dispatchKeys: $definition['dispatch_key'],
                surface: $definition['surface'],
            );

            if ($this->matchingDefinitions($effectiveDefinitions, $definition) === []) {
                $profilesDisabled[] = $profile->name;
            } else {
                $profilesReady[] = $profile->name;
            }
        }

        if ($profiles->isNotEmpty() && $profilesReady === []) {
            return [
                'channel' => $channel,
                'status' => self::STATUS_OPTIONAL,
                'status_label' => 'Optional / disabled',
                'summary' => 'This channel is explicitly disabled by every active schedule profile currently in use.',
                'source_labels' => $this->sourceLabels($matchingDefinitions),
                'profiles_disabled' => $profilesDisabled,
                'error' => null,
            ];
        }

        $summary = $profilesDisabled === []
            ? 'A compatible runtime message definition resolves successfully.'
            : 'A compatible runtime message definition resolves; some schedule profiles intentionally disable this channel.';

        return [
            'channel' => $channel,
            'status' => self::STATUS_READY,
            'status_label' => 'Ready',
            'summary' => $summary,
            'source_labels' => $this->sourceLabels($matchingDefinitions),
            'profiles_disabled' => $profilesDisabled,
            'error' => null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array{message_type: string, dispatch_key: string} $context
     * @return array<int, array<string, mixed>>
     */
    private function matchingDefinitions(array $definitions, array $context): array
    {
        return array_values(array_filter(
            $definitions,
            function (array $definition) use ($context): bool {
                if ($this->normalizeSegment((string) ($definition['message_type'] ?? '')) !== $this->normalizeSegment($context['message_type'])) {
                    return false;
                }

                $dispatchKeys = $definition['dispatch_keys'] ?? [];

                if (! is_array($dispatchKeys)) {
                    return false;
                }

                return in_array(
                    $this->normalizeSegment($context['dispatch_key']),
                    array_map(fn (mixed $key): string => $this->normalizeSegment((string) $key), $dispatchKeys),
                    true,
                );
            },
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @return array<int, string>
     */
    private function sourceLabels(array $definitions): array
    {
        $labels = [];

        foreach ($definitions as $definition) {
            if (data_get($definition, 'meta.message_template_preset.id') !== null) {
                $labels[] = 'DB template';
                continue;
            }

            if (is_string($definition['config_path'] ?? null) && trim((string) $definition['config_path']) !== '') {
                $labels[] = 'Config fallback';
                continue;
            }

            $labels[] = 'Runtime definition';
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array{profiles: Collection<int, WebinarScheduleProfile>, issues: array<int, string>}
     */
    public function profilesInUse(): array
    {
        $seriesProfileIds = WebinarSeries::query()
            ->whereNotNull('webinar_schedule_profile_id')
            ->pluck('webinar_schedule_profile_id')
            ->map(fn (mixed $id): int => (int) $id);

        $webinarProfileIds = Webinar::query()
            ->whereNotNull('webinar_schedule_profile_id')
            ->pluck('webinar_schedule_profile_id')
            ->map(fn (mixed $id): int => (int) $id);

        $explicitProfileIds = $seriesProfileIds
            ->merge($webinarProfileIds)
            ->unique()
            ->values();

        $ownersExist = WebinarSeries::query()->exists() || Webinar::query()->exists();
        $fallbackRequired = WebinarSeries::query()->whereNull('webinar_schedule_profile_id')->exists()
            || Webinar::query()->whereNull('webinar_schedule_profile_id')->exists();

        $issues = [];

        /** @var Collection<int, WebinarScheduleProfile> $explicitProfiles */
        $explicitProfiles = WebinarScheduleProfile::query()
            ->whereIn('id', $explicitProfileIds->all())
            ->with('items')
            ->get();

        $missingExplicitIds = $explicitProfileIds->diff($explicitProfiles->pluck('id')->map(fn (mixed $id): int => (int) $id));

        if ($missingExplicitIds->isNotEmpty()) {
            $issues[] = 'One or more webinar series or webinars reference a missing schedule profile.';
        }

        $inactiveExplicitProfiles = $explicitProfiles
            ->filter(fn (WebinarScheduleProfile $profile): bool => ! $profile->is_active || $profile->status !== WebinarScheduleProfile::STATUS_ACTIVE);

        if ($inactiveExplicitProfiles->isNotEmpty()) {
            $issues[] = 'One or more webinar series or webinars reference an inactive schedule profile.';
        }

        /** @var Collection<int, WebinarScheduleProfile> $activeDefaults */
        $activeDefaults = WebinarScheduleProfile::query()
            ->active()
            ->where('is_default', true)
            ->with('items')
            ->orderBy('id')
            ->get();

        if ($activeDefaults->count() > 1) {
            $issues[] = 'More than one active default webinar schedule profile exists.';
        }

        $profiles = $explicitProfiles
            ->filter(fn (WebinarScheduleProfile $profile): bool => $profile->is_active && $profile->status === WebinarScheduleProfile::STATUS_ACTIVE)
            ->values();

        if ((! $ownersExist || $fallbackRequired) && $activeDefaults->isNotEmpty()) {
            $profiles = $profiles->push($activeDefaults->first());
        }

        if ($ownersExist && $fallbackRequired && $activeDefaults->isEmpty()) {
            $issues[] = 'At least one webinar series or webinar relies on schedule-profile fallback, but no active default profile exists.';
        }

        return [
            'profiles' => $profiles
                ->unique(fn (WebinarScheduleProfile $profile): int => (int) $profile->getKey())
                ->values(),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * @param array{
     *     purpose: string,
     *     scope: string,
     *     surface: string
     * } $definition
     */
    private function isRequired(bool|string $rule, array $definition): bool
    {
        if (is_bool($rule)) {
            return $rule;
        }

        return match ($rule) {
            'registration_messaging_available',
            'waitlist_messaging_available' => $this->messageChannelAvailability->visibleChannelsForSurface(
                surface: $definition['surface'],
                purpose: $definition['purpose'],
                scope: $definition['scope'],
            ) !== [],
            'post_event_outcome_messages' => (bool) config('webinars.post_event.outcome_messages.enabled', true),
            default => false,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $readyStates
     * @param array<int, array<string, mixed>> $blockingStates
     * @param array<int, array<string, mixed>> $optionalStates
     */
    private function contextSummary(
        bool $required,
        array $readyStates,
        array $blockingStates,
        array $optionalStates,
    ): string {
        if ($blockingStates !== []) {
            return $required
                ? 'At least one currently available channel does not resolve to a compatible runtime message definition.'
                : 'This optional message area is not fully configured for every currently available channel.';
        }

        if ($readyStates !== []) {
            if ($optionalStates !== []) {
                return 'Ready on available channels, with one or more channels intentionally disabled by the active schedule profile.';
            }

            return 'All currently available channels resolve to compatible runtime message definitions.';
        }

        return 'This message area is currently optional or intentionally disabled.';
    }

    /**
     * @param array{ready: int, needs_attention: int, optional: int} $counts
     * @param array<int, string> $issues
     */
    private function overallSummary(string $status, array $counts, array $issues): string
    {
        if ($status === self::STATUS_READY) {
            return $counts['optional'] > 0
                ? 'Required webinar message areas are ready. Optional or intentionally disabled areas are called out separately.'
                : 'All configured webinar message areas resolve cleanly through current Messaging definitions and schedule profiles.';
        }

        $parts = [];

        if ($counts['needs_attention'] > 0) {
            $parts[] = $counts['needs_attention'].' message '.($counts['needs_attention'] === 1 ? 'area needs' : 'areas need').' attention';
        }

        if ($issues !== []) {
            $parts[] = count($issues).' schedule-profile '.(count($issues) === 1 ? 'issue was' : 'issues were').' found';
        }

        return ucfirst(implode(' and ', $parts)).'.';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_READY => 'Ready',
            self::STATUS_NEEDS_ATTENTION => 'Needs attention',
            default => 'Optional / disabled',
        };
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
