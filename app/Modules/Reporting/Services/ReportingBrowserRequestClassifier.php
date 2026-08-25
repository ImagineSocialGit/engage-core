<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Data\ReportingRequestClassification;
use Illuminate\Http\Request;
use LogicException;

final class ReportingBrowserRequestClassifier
{
    public const CONFIG_KEY = 'request_signals_v3';
    public const CLASSIFIER_KEY = 'browser_request_signals';
    public const CLASSIFIER_VERSION = 3;

    private const AUTOMATION_PATTERN = '/(?:bot|crawler|spider|slurp|bingpreview|facebookexternalhit|facebot|twitterbot|linkedinbot|discordbot|telegrambot|whatsapp|yandexbot|baiduspider|duckduckbot|semrushbot|ahrefsbot|headlesschrome|phantomjs|selenium|playwright|puppeteer|curl\/|wget\/|python-requests|go-http-client|apache-httpclient)/i';

    public function classify(Request $request): ReportingRequestClassification
    {
        $configured = config('reporting.classification.browser_classifier', self::CONFIG_KEY);

        if ($configured !== self::CONFIG_KEY) {
            throw new LogicException(
                'Unsupported Reporting browser classifier configuration.',
            );
        }

        $userAgent = trim((string) $request->userAgent());
        $browserFamily = $this->browserFamily($userAgent);
        $deviceClass = $this->deviceClass($userAgent, $browserFamily);
        $osFamily = $this->osFamily($userAgent);
        $fetchSite = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));

        if ($userAgent === '') {
            return $this->classification(
                trafficClass: 'unknown',
                reasons: ['user_agent_missing'],
            );
        }

        if (preg_match(self::AUTOMATION_PATTERN, $userAgent) === 1) {
            return $this->classification(
                trafficClass: 'likely_automated',
                reasons: ['automation_user_agent'],
                deviceClass: $deviceClass,
                browserFamily: $browserFamily,
                osFamily: $osFamily,
            );
        }

        if ($browserFamily === null) {
            return $this->classification(
                trafficClass: 'unknown',
                reasons: ['user_agent_unrecognized'],
                deviceClass: $deviceClass,
                osFamily: $osFamily,
            );
        }

        if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
            return $this->classification(
                trafficClass: 'unknown',
                reasons: [
                    'browser_family_recognized',
                    ...$this->browserRecognitionReasons($browserFamily),
                    'fetch_metadata_not_same_origin',
                ],
                deviceClass: $deviceClass,
                browserFamily: $browserFamily,
                osFamily: $osFamily,
            );
        }

        return $this->classification(
            trafficClass: 'likely_human',
            reasons: [
                'browser_family_recognized',
                ...$this->browserRecognitionReasons($browserFamily),
                $fetchSite === 'same-origin'
                    ? 'same_origin_fetch_metadata'
                    : 'fetch_metadata_missing',
            ],
            deviceClass: $deviceClass,
            browserFamily: $browserFamily,
            osFamily: $osFamily,
        );
    }

    /**
     * @param array<int, string> $reasons
     */
    private function classification(
        string $trafficClass,
        array $reasons,
        ?string $deviceClass = null,
        ?string $browserFamily = null,
        ?string $osFamily = null,
    ): ReportingRequestClassification {
        return new ReportingRequestClassification(
            trafficClass: $trafficClass,
            classifierKey: self::CLASSIFIER_KEY,
            classifierVersion: self::CLASSIFIER_VERSION,
            reasons: $reasons,
            deviceClass: $deviceClass,
            browserFamily: $browserFamily,
            osFamily: $osFamily,
        );
    }

    private function browserFamily(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        return match (true) {
            preg_match('/(?:\bMessengerForiOS\b|\bFBAN\/MessengerForiOS\b|\bOrca-Android\b)/i', $userAgent) === 1 => 'Messenger In-App',
            preg_match('/\bInstagram(?:\s|\/)/i', $userAgent) === 1 => 'Instagram In-App',
            preg_match('/(?:\bFBAN\/|\bFBAV\/|\bFB_IAB\/|\bFBIOS\b|\bFB4A\b)/i', $userAgent) === 1 => 'Facebook In-App',
            $this->isAndroidWebView($userAgent) => 'Android WebView',
            preg_match('/\bEdg(?:A|iOS)?\//', $userAgent) === 1 => 'Edge',
            preg_match('/\bOPR\//', $userAgent) === 1 => 'Opera',
            preg_match('/\bSamsungBrowser\//', $userAgent) === 1 => 'Samsung Internet',
            preg_match('/\b(?:Chrome|CriOS)\//', $userAgent) === 1 => 'Chrome',
            preg_match('/\b(?:Firefox|FxiOS)\//', $userAgent) === 1 => 'Firefox',
            preg_match('/\bSafari\//', $userAgent) === 1
                && preg_match('/\bVersion\//', $userAgent) === 1 => 'Safari',
            $this->isIosWebView($userAgent) => 'iOS WebView',
            default => null,
        };
    }

    /** @return array<int, string> */
    private function browserRecognitionReasons(string $browserFamily): array
    {
        return match ($browserFamily) {
            'Instagram In-App', 'Facebook In-App', 'Messenger In-App' => [
                'in_app_browser_recognized',
            ],
            'Android WebView', 'iOS WebView' => [
                'embedded_webview_recognized',
            ],
            default => [],
        };
    }

    private function isAndroidWebView(string $userAgent): bool
    {
        return preg_match('/\bAndroid\b/i', $userAgent) === 1
            && (
                preg_match('/;\s*wv\)/i', $userAgent) === 1
                || (
                    preg_match('/\bVersion\/4\.0\b/i', $userAgent) === 1
                    && preg_match('/\bChrome\//i', $userAgent) === 1
                )
            );
    }

    private function isIosWebView(string $userAgent): bool
    {
        return preg_match('/\b(?:iPhone|iPad|iPod)\b/i', $userAgent) === 1
            && preg_match('/\bAppleWebKit\//i', $userAgent) === 1
            && preg_match('/\bMobile\//i', $userAgent) === 1
            && preg_match('/\bSafari\//i', $userAgent) !== 1;
    }

    private function deviceClass(string $userAgent, ?string $browserFamily): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        if (preg_match('/\b(?:iPad|Tablet)\b/i', $userAgent) === 1) {
            return 'tablet';
        }

        if (preg_match('/\b(?:iPhone|iPod|Mobile)\b/i', $userAgent) === 1) {
            return 'mobile';
        }

        if (preg_match('/\bAndroid\b/i', $userAgent) === 1) {
            return preg_match('/\bMobile\b/i', $userAgent) === 1
                ? 'mobile'
                : 'tablet';
        }

        return $browserFamily !== null
            ? 'desktop'
            : null;
    }

    private function osFamily(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        return match (true) {
            preg_match('/\bAndroid\b/i', $userAgent) === 1 => 'Android',
            preg_match('/\b(?:iPhone|iPad|iPod)\b/i', $userAgent) === 1 => 'iOS',
            preg_match('/\bWindows NT\b/i', $userAgent) === 1 => 'Windows',
            preg_match('/\bCrOS\b/i', $userAgent) === 1 => 'ChromeOS',
            preg_match('/\bMac OS X\b/i', $userAgent) === 1 => 'macOS',
            preg_match('/\bLinux\b/i', $userAgent) === 1 => 'Linux',
            default => null,
        };
    }
}