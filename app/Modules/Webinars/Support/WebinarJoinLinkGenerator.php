<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\Urls\AbsoluteUrl;

class WebinarJoinLinkGenerator
{
    public function __construct(
        private readonly WebinarJoinBrowserProof $browserProof,
    ) {}

    public function forRegistration(WebinarRegistration $registration): string
    {
        $path = route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ], false);

        return AbsoluteUrl::join(
            config('app.webinar_url', config('app.url')),
            $path,
        ).'#join_proof='.rawurlencode(
            $this->browserProof->issue($registration),
        );
    }
}