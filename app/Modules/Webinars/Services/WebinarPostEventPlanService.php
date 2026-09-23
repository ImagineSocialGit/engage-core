<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WebinarPostEventPlanService
{
    public const TRIGGERS = [
        'webinar.ended' => 'Meeting or webinar ended',
        'webinar.attendance_reconciled' => 'Attendance reconciled (from provider records)',
        'webinar.recording_completed' => 'Recording completed',
    ];

    public const OUTCOMES = ['any' => 'All registrants', 'attended' => 'Attended', 'missed' => 'Missed'];

    public const CONDITION_FAILURE_ACTIONS = [
        'skip' => "Don't send a message",
        'alternate' => 'Send alternate message',
    ];

    public function __construct(private readonly WebinarPostEventSendConditionRegistry $conditions) {}

    /** @return array<string, string> */
    public function sendConditions(): array
    {
        return $this->conditions->options();
    }

    /** @return array<string, mixed>|null */
    public function forSeries(WebinarSeries $series): ?array
    {
        $configured = config('webinars.post_event_plans.'.$series->slug);

        $configured = is_array($configured) && $configured !== []
            ? $configured : ['enabled' => false, 'rules' => []];

        $saved = data_get($series->meta, 'post_event_plan');
        $plan = is_array($saved) ? $saved : array_replace($configured, ['enabled' => false]);

        if (! is_array($plan['rules'] ?? null)) {
            $plan['rules'] = $this->legacyRules($plan);
        }

        return $plan;
    }

    /** @return array<string, mixed>|null */
    public function activeFor(Webinar $webinar): ?array
    {
        $webinar->loadMissing('webinarSeries');
        $series = $webinar->webinarSeries;

        if (! $series instanceof WebinarSeries) {
            return null;
        }

        $plan = $this->forSeries($series);
        $activatedAt = $plan['activated_at'] ?? null;

        if (! is_array($plan) || ($plan['enabled'] ?? false) !== true
            || ! is_string($activatedAt) || $activatedAt === '' || ! $webinar->ends_at
            || $webinar->ends_at->lessThan(Carbon::parse($activatedAt))) {
            return null;
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan @return array<string, array<string, mixed>> */
    public function rules(array $plan): array
    {
        return is_array($plan['rules'] ?? null) ? $plan['rules'] : $this->legacyRules($plan);
    }

    /** @param array<string, mixed> $plan @return array<string, mixed>|null */
    public function rule(array $plan, string $ruleId): ?array
    {
        $rule = $this->rules($plan)[$ruleId] ?? null;

        return is_array($rule) ? $rule : null;
    }

    public function saveActivation(WebinarSeries $series, bool $enabled): WebinarSeries
    {
        return DB::transaction(function () use ($series, $enabled): WebinarSeries {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $plan = $this->forSeries($locked);

            if ($plan === null) {
                throw new InvalidArgumentException('This webinar type has no configured follow-up plan.');
            }

            if ($enabled) {
                $activeRules = array_filter($this->rules($plan), fn (array $rule): bool => ($rule['enabled'] ?? false) === true);

                if ($activeRules === []) {
                    throw new InvalidArgumentException('Enable at least one follow-up message.');
                }

                foreach ($activeRules as $rule) {
                    $this->validateRule($rule);
                    $this->assertTemplateReady($rule);
                }

                if (($plan['enabled'] ?? false) !== true) {
                    $plan['activated_at'] = now()->toIso8601String();
                }
            }

            $plan['enabled'] = $enabled;
            $this->persist($locked, $plan);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /** @param array<string, mixed> $options */
    public function saveRule(WebinarSeries $series, string $ruleId, array $options): WebinarSeries
    {
        return DB::transaction(function () use ($series, $ruleId, $options): WebinarSeries {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $plan = $this->forSeries($locked);
            $rule = is_array($plan) ? $this->rule($plan, $ruleId) : null;

            if ($rule === null) {
                throw new InvalidArgumentException('This follow-up message no longer exists.');
            }

            $rule = array_replace($rule, $options);
            $this->validateRule($rule);
            $this->assertUniqueTemplates($this->rules($plan), $rule, $ruleId);
            if (($rule['enabled'] ?? false) === true) {
                $this->assertTemplateReady($rule);
            }
            $rule['revision'] = (int) ($rule['revision'] ?? 1) + 1;

            // Edits to an active plan affect future occurrences. Older queued messages
            // for this rule fail the revision guard at send time.
            if (($plan['enabled'] ?? false) === true) {
                $rule['effective_at'] = now()->toIso8601String();
            }

            $plan['rules'][$ruleId] = $rule;
            if (($plan['enabled'] ?? false) === true
                && ! collect($plan['rules'])->contains(fn ($item): bool => is_array($item) && ($item['enabled'] ?? false) === true)) {
                throw new InvalidArgumentException('Keep one message enabled or disable the plan first.');
            }
            $this->persist($locked, $plan);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /** @param array<string, mixed> $options */
    public function addRule(WebinarSeries $series, array $options): WebinarSeries
    {
        return DB::transaction(function () use ($series, $options): WebinarSeries {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $plan = $this->forSeries($locked);

            if ($plan === null || count($this->rules($plan)) >= 20) {
                throw new InvalidArgumentException('The follow-up message limit has been reached.');
            }

            $rule = array_replace([
                'enabled' => false,
                'channel' => 'email',
                'trigger' => 'webinar.attendance_reconciled',
                'outcome' => 'any',
                'send_condition' => 'always',
                'on_condition_failure' => 'skip',
                'alternate_template_key' => null,
                'delay_unit' => 'minutes',
                'delay_value' => 10,
                'send_time' => null,
                'revision' => 1,
            ], $options);
            $this->validateRule($rule);
            $this->assertUniqueTemplates($this->rules($plan), $rule);
            $id = (string) Str::uuid();
            $plan['rules'][$id] = $rule;
            $this->persist($locked, $plan);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function deleteRule(WebinarSeries $series, string $ruleId): WebinarSeries
    {
        return DB::transaction(function () use ($series, $ruleId): WebinarSeries {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $plan = $this->forSeries($locked);

            if ($plan === null || $this->rule($plan, $ruleId) === null) {
                throw new InvalidArgumentException('This follow-up message no longer exists.');
            }

            unset($plan['rules'][$ruleId]);
            if (($plan['enabled'] ?? false) === true
                && ! collect($plan['rules'])->contains(fn ($item): bool => is_array($item) && ($item['enabled'] ?? false) === true)) {
                throw new InvalidArgumentException('Keep one message enabled or disable the plan first.');
            }
            $this->persist($locked, $plan);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /** @param array<string, mixed> $plan */
    private function persist(WebinarSeries $series, array $plan): void
    {
        $meta = is_array($series->meta) ? $series->meta : [];
        data_set($meta, 'post_event_plan', $plan);
        $series->forceFill(['meta' => $meta])->save();
    }

    /** @param array<string, mixed> $rule */
    public function validateRule(array $rule): void
    {
        if (! in_array($rule['channel'] ?? null, ['sms', 'email'], true)
            || ! array_key_exists((string) ($rule['trigger'] ?? ''), self::TRIGGERS)
            || ! array_key_exists((string) ($rule['outcome'] ?? ''), self::OUTCOMES)
            || ! $this->conditions->has((string) ($rule['send_condition'] ?? ''))
            || ! array_key_exists((string) ($rule['on_condition_failure'] ?? ''), self::CONDITION_FAILURE_ACTIONS)
            || ! in_array($rule['delay_unit'] ?? null, ['minutes', 'days'], true)
            || ! is_int($rule['delay_value'] ?? null)
            || ($rule['delay_value'] ?? -1) < 0
            || ($rule['delay_value'] ?? 0) > (($rule['delay_unit'] ?? null) === 'days' ? 365 : 1440)
            || ! is_string($rule['template_key'] ?? null) || $rule['template_key'] === '') {
            throw new InvalidArgumentException('The follow-up message settings are invalid.');
        }

        $time = $rule['send_time'] ?? null;

        if ($time !== null && ($rule['delay_unit'] !== 'days'
            || ! is_string($time)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1)) {
            throw new InvalidArgumentException('A send time can only accompany a day delay.');
        }

        $alternate = $rule['alternate_template_key'] ?? null;
        if (($rule['send_condition'] === 'always' || $rule['on_condition_failure'] === 'skip')
            && $alternate !== null) {
            throw new InvalidArgumentException('An alternate template requires a send condition and alternate action.');
        }

        if ($rule['on_condition_failure'] === 'alternate'
            && ($rule['send_condition'] === 'always' || ! is_string($alternate)
                || $alternate === '' || $alternate === $rule['template_key'])) {
            throw new InvalidArgumentException('Select a distinct alternate template for the unmet condition.');
        }
    }

    /** @param array<string, mixed> $rule */
    public function assertTemplateReady(array $rule): void
    {
        $this->assertOneTemplateReady($rule['template_key'], $rule['channel']);

        if ($rule['on_condition_failure'] === 'alternate') {
            $this->assertOneTemplateReady($rule['alternate_template_key'], $rule['channel']);
        }
    }

    private function assertOneTemplateReady(string $key, string $channel): void
    {
        $template = MessageTemplate::query()->where('key', $key)->first();
        $preset = MessageTemplatePreset::query()->where('key', $key)->first();

        if (! $template || ! $template->isActive()
            || $template->channel !== $channel || ! $template->currentVersion
            || ! $preset || ! $preset->isActive()
            || $preset->channel !== $channel
            || $preset->purpose !== 'transactional' || $preset->scope !== 'webinar'
            || ! in_array('webinar_post_event_plan', $preset->dispatchKeys(), true)) {
            throw new InvalidArgumentException('The selected message template is not published and active.');
        }
    }

    /** @param array<string, array<string, mixed>> $existing @param array<string, mixed> $candidate */
    private function assertUniqueTemplates(array $existing, array $candidate, ?string $ignoreId = null): void
    {
        $selected = array_filter([$candidate['template_key'], $candidate['alternate_template_key'] ?? null]);

        foreach ($existing as $id => $rule) {
            if ((string) $id === $ignoreId || ! is_array($rule)) {
                continue;
            }

            if (array_intersect($selected, array_filter([
                $rule['template_key'] ?? null,
                $rule['alternate_template_key'] ?? null,
            ])) !== []) {
                throw new InvalidArgumentException('Each follow-up message needs its own templates.');
            }
        }
    }

    /** @param array<string, mixed> $rule */
    public function selectedTemplateKey(array $rule, bool $conditionMatches): ?string
    {
        if ($conditionMatches) {
            return $rule['template_key'];
        }

        return $rule['on_condition_failure'] === 'alternate'
            ? ($rule['alternate_template_key'] ?? null) : null;
    }

    /** @param array<string, mixed> $plan @return array<string, array<string, mixed>> */
    private function legacyRules(array $plan): array
    {
        $rules = [];
        foreach (['attended', 'missed'] as $outcome) {
            $rules[$outcome.'_sms'] = [
                'enabled' => (bool) ($plan['sms_enabled'] ?? true),
                'channel' => 'sms',
                'template_key' => data_get($plan, 'templates.sms.'.$outcome, ''),
                'trigger' => 'webinar.attendance_reconciled',
                'outcome' => $outcome,
                'send_condition' => 'always',
                'on_condition_failure' => 'skip',
                'alternate_template_key' => null,
                'delay_unit' => 'minutes',
                'delay_value' => (int) ($plan['sms_delay_minutes'] ?? 10),
                'send_time' => null,
                'revision' => 1,
            ];
            $rules[$outcome.'_email'] = [
                'enabled' => (bool) ($plan['email_enabled'] ?? true),
                'channel' => 'email',
                'template_key' => data_get($plan, 'templates.email.recording.'.$outcome, ''),
                'trigger' => 'webinar.ended',
                'outcome' => $outcome,
                'send_condition' => 'recording_exists',
                'on_condition_failure' => 'alternate',
                'alternate_template_key' => data_get($plan, 'templates.email.fallback.'.$outcome, ''),
                'delay_unit' => 'days',
                'delay_value' => 1,
                'send_time' => (string) ($plan['email_time'] ?? '09:00'),
                'revision' => 1,
            ];
        }

        return $rules;
    }

    /** @return array<string, array<int, string>> */
    public function eventCatalog(): array
    {
        $native = config('webinars.providers.zoom.webhook_events', []);

        return ['provider_events' => is_array($native) ? array_keys($native) : []];
    }
}