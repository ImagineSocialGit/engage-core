<?php

namespace App\Support\Webinars;

use App\Models\WebinarRegistration;

class WebinarJoinLinkGenerator
{
    public function forRegistration(WebinarRegistration $registration): string
    {
        $path = route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ], false);

        return rtrim(config('app.webinar_url', config('app.url')), '/') . $path;
    }
}