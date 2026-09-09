<?php

namespace App\Integrations\HumanVerification\Turnstile;

use App\Support\HumanVerification\Contracts\HumanVerificationProvider;
use App\Support\HumanVerification\Data\HumanVerificationRequest;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Data\HumanVerificationWidget;
use App\Support\HumanVerification\Exceptions\HumanVerificationConfigurationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class TurnstileHumanVerificationProvider implements HumanVerificationProvider
{
    public const KEY = 'turnstile';
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const WIDGET_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

    public function key(): string
    {
        return self::KEY;
    }

    public function validateConfiguration(array $configuration): void
    {
        foreach (['site_key', 'secret_key'] as $key) {
            if (! is_string($configuration[$key] ?? null)
                || trim((string) $configuration[$key]) === ''
            ) {
                throw new HumanVerificationConfigurationException(
                    "Turnstile [{$key}] is required when public human verification is enabled.",
                );
            }
        }

        $url = trim((string) ($configuration['siteverify_url'] ?? self::SITEVERIFY_URL));

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new HumanVerificationConfigurationException(
                'Turnstile siteverify URL is invalid.',
            );
        }

        foreach ([
            'timeout_seconds' => [1, 30],
            'connect_timeout_seconds' => [1, 30],
            'retry_attempts' => [1, 5],
            'retry_sleep_milliseconds' => [0, 5000],
            'challenge_max_age_seconds' => [30, 600],
        ] as $key => [$minimum, $maximum]) {
            $value = $configuration[$key] ?? null;

            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                throw new HumanVerificationConfigurationException(
                    "Turnstile [{$key}] must be an integer between {$minimum} and {$maximum}.",
                );
            }
        }
    }

    public function widget(
        string $action,
        array $configuration,
    ): HumanVerificationWidget {
        $this->validateConfiguration($configuration);

        return new HumanVerificationWidget(
            provider: self::KEY,
            scriptUrl: self::WIDGET_SCRIPT_URL,
            siteKey: trim((string) $configuration['site_key']),
            action: $action,
        );
    }

    public function verify(
        HumanVerificationRequest $request,
        array $configuration,
    ): HumanVerificationResult {
        $this->validateConfiguration($configuration);

        if ($request->token === '' || mb_strlen($request->token) > 2048) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_INVALID_TOKEN,
            );
        }

        $response = $this->siteverify($request, $configuration);

        if (! $response instanceof Response || ! $response->successful()) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_UNAVAILABLE,
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_INVALID_RESPONSE,
            );
        }

        if (($payload['success'] ?? false) !== true) {
            $errorCodes = is_array($payload['error-codes'] ?? null)
                ? $payload['error-codes']
                : [];

            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: in_array('timeout-or-duplicate', $errorCodes, true)
                    ? HumanVerificationResult::REASON_EXPIRED_OR_DUPLICATE
                    : HumanVerificationResult::REASON_INVALID_TOKEN,
            );
        }

        $hostname = strtolower(trim((string) ($payload['hostname'] ?? '')));
        $action = trim((string) ($payload['action'] ?? ''));
        $challengeAt = $this->challengeAt($payload['challenge_ts'] ?? null);

        if ($hostname === ''
            || ! in_array($hostname, $request->expectedHostnames, true)
        ) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_HOSTNAME_MISMATCH,
                challengeAt: $challengeAt,
                hostname: $hostname !== '' ? $hostname : null,
                action: $action !== '' ? $action : null,
            );
        }

        if ($action !== $request->expectedAction) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_ACTION_MISMATCH,
                challengeAt: $challengeAt,
                hostname: $hostname,
                action: $action !== '' ? $action : null,
            );
        }

        if (! $challengeAt instanceof CarbonImmutable) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_INVALID_RESPONSE,
                hostname: $hostname,
                action: $action,
            );
        }

        if ($challengeAt->lt(
            CarbonImmutable::now('UTC')->subSeconds(
                (int) $configuration['challenge_max_age_seconds'],
            ),
        )) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_STALE_CHALLENGE,
                challengeAt: $challengeAt,
                hostname: $hostname,
                action: $action,
            );
        }

        return HumanVerificationResult::verified(
            provider: self::KEY,
            verifiedAt: CarbonImmutable::now('UTC'),
            challengeAt: $challengeAt,
            hostname: $hostname,
            action: $action,
        );
    }

    /** @param array<string, mixed> $configuration */
    private function siteverify(
        HumanVerificationRequest $request,
        array $configuration,
    ): ?Response {
        $attempts = (int) $configuration['retry_attempts'];
        $sleepMilliseconds = (int) $configuration['retry_sleep_milliseconds'];
        $idempotencyKey = (string) Str::uuid();
        $url = trim((string) $configuration['siteverify_url']);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::asForm()
                    ->connectTimeout((int) $configuration['connect_timeout_seconds'])
                    ->timeout((int) $configuration['timeout_seconds'])
                    ->post($url, array_filter([
                        'secret' => trim((string) $configuration['secret_key']),
                        'response' => $request->token,
                        'remoteip' => $request->remoteIp,
                        'idempotency_key' => $idempotencyKey,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            } catch (ConnectionException) {
                $response = null;
            } catch (Throwable) {
                return null;
            }

            if ($response instanceof Response && ! $response->serverError()) {
                return $response;
            }

            if ($attempt < $attempts && $sleepMilliseconds > 0) {
                usleep($sleepMilliseconds * 1000);
            }
        }

        return $response ?? null;
    }

    private function challengeAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}