<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\Webinar;
use Illuminate\Support\Str;
use RuntimeException;

class WebinarPlaybackLinkGenerator
{
    public function forWebinar(Webinar $webinar): string
    {
        if (blank($webinar->playback_token)) {
            $webinar->forceFill([
                'playback_token' => Str::random(48),
            ])->save();
        }

        $routeUrl = route('webinar.playback.redirect', [
            'token' => $webinar->playback_token,
        ]);

        $path = parse_url($routeUrl, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException(
                'Unable to resolve the Webinar playback route path.',
            );
        }

        $baseUrl = rtrim(
            (string) config('app.webinar_url', config('app.url')),
            '/',
        );

        return $baseUrl.'/'.ltrim($path, '/');
    }
}