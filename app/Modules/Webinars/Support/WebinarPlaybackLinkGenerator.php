<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\Webinar;
use App\Support\Urls\AbsoluteUrl;
use Illuminate\Support\Str;

class WebinarPlaybackLinkGenerator
{
    public function forWebinar(Webinar $webinar): string
    {
        if (blank($webinar->playback_token)) {
            $webinar->forceFill([
                'playback_token' => Str::random(48),
            ])->save();
        }

        $path = route('webinar.playback.redirect', [
            'token' => $webinar->playback_token,
        ], false);

        return AbsoluteUrl::join(
            config('app.webinar_url', config('app.url')),
            $path,
        );
    }
}