<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Validation\ValidationException;

class WebinarTimeChangeTemplates
{
    public const TOKENS = [
        'first_name', 'webinar_title', 'previous_webinar_time', 'current_webinar_time',
    ];

    public function __construct(private readonly MessageChannelAvailability $availability) {}

    public function key(WebinarSeries $series, string $channel): string
    {
        return 'webinar_series_'.$series->getKey().'_time_change_'.$channel;
    }

    public function template(WebinarSeries $series, string $channel): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('key', $this->key($series, $channel))
            ->where('channel', $channel)
            ->active()->with('currentVersion')->first();
    }

    public function version(WebinarSeries $series, string $channel): ?MessageTemplateVersion
    {
        return $this->template($series, $channel)?->currentVersion;
    }

    public function configuredChannels(WebinarSeries $series): array
    {
        $policy = data_get($series->meta, 'time_change_notifications', []);

        return is_array($policy) && is_array($policy['channels'] ?? null)
            ? array_values(array_intersect(['email', 'sms'], $policy['channels']))
            : [];
    }

    public function autoSend(WebinarSeries $series): bool
    {
        return data_get($series->meta, 'time_change_notifications.auto_send') === true;
    }

    public function availableChannels(): array
    {
        return $this->availability->visibleChannelsForSurface(
            surface: 'webinar_registrations', purpose: 'transactional',
            scope: 'webinar', requireProvider: true,
        );
    }

    /** @return array<string, int> */
    public function versionsFor(WebinarSeries $series, array $channels): array
    {
        $versions = [];

        foreach ($channels as $channel) {
            $version = $this->version($series, $channel);

            if ($version) {
                $versions[$channel] = (int) $version->getKey();
            }
        }

        return $versions;
    }

    public function validatedAutoVersions(WebinarSeries $series, array $channels): array
    {
        $channels = array_values(array_unique($channels));
        $available = $this->availableChannels();
        $versions = $this->versionsFor($series, $channels);

        if ($channels === [] || array_diff($channels, $available)
            || count($versions) !== count($channels)) {
            throw ValidationException::withMessages([
                'channels' => 'Auto-send requires a published time-change template and an available provider for every selected channel.',
            ]);
        }

        return $versions;
    }

    public function validateCopy(string $channel, array $payload): void
    {
        $values = $channel === 'email'
            ? [$payload['subject'] ?? '', $payload['body'] ?? '']
            : [$payload['message'] ?? ''];

        foreach ($values as $value) {
            preg_match_all('/\{([^{}]+)\}/', (string) $value, $matches);

            if (array_diff($matches[1], self::TOKENS)) {
                throw ValidationException::withMessages([
                    'copy' => 'Only the listed time-change tokens can be used in this message.',
                ]);
            }
        }

        $body = $channel === 'email' ? (string) ($payload['body'] ?? '') : (string) ($payload['message'] ?? '');

        if (! str_contains($body, '{previous_webinar_time}')
            || ! str_contains($body, '{current_webinar_time}')) {
            throw ValidationException::withMessages([
                'copy' => 'The message must include both the previous and current webinar times.',
            ]);
        }
    }
}