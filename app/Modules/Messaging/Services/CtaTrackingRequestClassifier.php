<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\ScheduledMessageCtaEngagement;
use Illuminate\Http\Request;

class CtaTrackingRequestClassifier
{
    /**
     * This is deliberately conservative. "likely_human" requires browser
     * navigation evidence; everything else remains scanner/prefetch/unknown.
     */
    public function classify(Request $request): string
    {
        if ($request->isMethod('HEAD') || $this->hasPrefetchSignal($request)) {
            return ScheduledMessageCtaEngagement::CLASSIFICATION_PREFETCH;
        }

        if ($this->hasScannerSignal($request)) {
            return ScheduledMessageCtaEngagement::CLASSIFICATION_SCANNER;
        }

        if ($this->hasLikelyHumanNavigationSignal($request)) {
            return ScheduledMessageCtaEngagement::CLASSIFICATION_LIKELY_HUMAN;
        }

        return ScheduledMessageCtaEngagement::CLASSIFICATION_UNKNOWN;
    }

    private function hasPrefetchSignal(Request $request): bool
    {
        foreach ([
            'Purpose',
            'Sec-Purpose',
            'X-Purpose',
            'X-Moz',
        ] as $header) {
            $value = strtolower(trim((string) $request->header($header, '')));

            if ($value !== ''
                && (str_contains($value, 'prefetch')
                    || str_contains($value, 'preview')
                    || str_contains($value, 'prerender'))
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasScannerSignal(Request $request): bool
    {
        $userAgent = strtolower(trim((string) $request->userAgent()));

        if ($userAgent === '') {
            return false;
        }

        foreach ([
            'proofpoint',
            'mimecast',
            'barracuda',
            'safelinks',
            'microsoft office',
            'googleimageproxy',
            'google-inspectiontool',
            'urlscan',
            'linkchecker',
            'symantec',
            'sophos',
            'trendmicro',
            'trend micro',
            'zscaler',
            'facebookexternalhit',
            'slackbot',
            'discordbot',
            'telegrambot',
            'whatsapp',
            'linkedinbot',
            'twitterbot',
            'curl/',
            'wget/',
            'python-requests',
            'go-http-client',
        ] as $needle) {
            if (str_contains($userAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasLikelyHumanNavigationSignal(Request $request): bool
    {
        $mode = strtolower(trim((string) $request->header('Sec-Fetch-Mode', '')));
        $destination = strtolower(trim((string) $request->header('Sec-Fetch-Dest', '')));
        $user = strtolower(trim((string) $request->header('Sec-Fetch-User', '')));

        return $mode === 'navigate'
            && $destination === 'document'
            && $user === '?1';
    }
}