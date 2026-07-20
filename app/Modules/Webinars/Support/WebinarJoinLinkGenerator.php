<?php

namespace App\Modules\Webinars\Support;

use App\Modules\Webinars\Models\WebinarRegistration;
use RuntimeException;

class WebinarJoinLinkGenerator
{
    public function __construct(
        private readonly WebinarJoinBrowserProof $browserProof,
    ) {}

    public function forRegistration(WebinarRegistration $registration): string
    {
        $routeUrl = route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ]);

        $path = parse_url($routeUrl, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException(
                'Unable to resolve the Webinar join route path.',
            );
        }

        $baseUrl = rtrim(
            (string) config('app.webinar_url', config('app.url')),
            '/',
        );

        $url = $baseUrl.'/'.ltrim($path, '/');

        return $url.'#join_proof='.rawurlencode(
            $this->browserProof->issue($registration),
        );
    }
}