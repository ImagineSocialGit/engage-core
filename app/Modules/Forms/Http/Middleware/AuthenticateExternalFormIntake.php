<?php

namespace App\Modules\Forms\Http\Middleware;

use App\Modules\Forms\Data\ExternalFormIntakeClient;
use App\Modules\Forms\Services\ExternalFormIntakeClientResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthenticateExternalFormIntake
{
    public const CLIENT_ATTRIBUTE = 'forms.external_intake.client';

    private const CLIENT_HEADER = 'X-Engage-Client';
    private const TIMESTAMP_HEADER = 'X-Engage-Timestamp';
    private const NONCE_HEADER = 'X-Engage-Nonce';
    private const SIGNATURE_HEADER = 'X-Engage-Signature';

    public function __construct(
        private readonly ExternalFormIntakeClientResolver $clients,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('forms.external_intake.enabled', false);

        if (! is_bool($enabled)) {
            return $this->configurationUnavailable($request);
        }

        if (! $enabled) {
            return $this->error(
                request: $request,
                status: 404,
                code: 'external_intake_unavailable',
                message: 'External form intake is unavailable.',
            );
        }

        $maximumBodyBytes = $this->boundedInteger('max_body_bytes', 1024, 10 * 1024 * 1024);
        $timestampDrift = $this->boundedInteger('max_timestamp_drift_seconds', 30, 3600);
        $nonceTtl = $this->boundedInteger('nonce_ttl_seconds', 60, 7200);
        $anonymousLimit = $this->boundedInteger('unauthenticated_rate_limit_per_minute', 1, 10000);
        $clientLimit = $this->boundedInteger('client_rate_limit_per_minute', 1, 10000);

        if ($maximumBodyBytes === null
            || $timestampDrift === null
            || $nonceTtl === null
            || $anonymousLimit === null
            || $clientLimit === null
            || $nonceTtl < ($timestampDrift * 2)
        ) {
            return $this->configurationUnavailable($request);
        }

        if (strlen($request->getContent()) > $maximumBodyBytes) {
            return $this->error(
                request: $request,
                status: 413,
                code: 'payload_too_large',
                message: 'The external form intake payload is too large.',
            );
        }

        if (! $request->isJson()) {
            return $this->error(
                request: $request,
                status: 415,
                code: 'unsupported_media_type',
                message: 'External form intake requires application/json.',
            );
        }

        try {
            $limited = $this->rateLimit(
                request: $request,
                key: 'anonymous:'.hash('sha256', (string) ($request->ip() ?? 'unknown')),
                maximumAttempts: $anonymousLimit,
            );
        } catch (Throwable $exception) {
            return $this->authenticationUnavailable($request, $exception);
        }

        if ($limited instanceof JsonResponse) {
            return $limited;
        }

        $clientId = trim((string) $request->header(self::CLIENT_HEADER, ''));

        try {
            $client = $clientId !== '' ? $this->clients->find($clientId) : null;
        } catch (InvalidArgumentException $exception) {
            return $this->configurationUnavailable($request, $exception);
        }

        if (! $client instanceof ExternalFormIntakeClient) {
            return $this->authenticationFailed($request);
        }

        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER, ''));
        $nonce = trim((string) $request->header(self::NONCE_HEADER, ''));
        $signature = trim((string) $request->header(self::SIGNATURE_HEADER, ''));

        if (preg_match('/^\d{10}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > $timestampDrift
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $nonce) !== 1
            || preg_match('/^v1=([0-9a-f]{64})$/i', $signature, $matches) !== 1
        ) {
            return $this->authenticationFailed($request);
        }

        $canonicalRequest = implode("\n", [
            'v1',
            $client->id,
            $timestamp,
            strtolower($nonce),
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            hash('sha256', $request->getContent()),
        ]);

        if (! $client->verifies($canonicalRequest, $matches[1])) {
            return $this->authenticationFailed($request);
        }

        $formKey = (string) $request->route('form');

        if (! $client->allowsForm($formKey)) {
            return $this->error(
                request: $request,
                status: 403,
                code: 'form_not_allowed',
                message: 'This external intake client is not allowed to submit the requested form.',
            );
        }

        try {
            $limited = $this->rateLimit(
                request: $request,
                key: 'client:'.hash('sha256', $client->id),
                maximumAttempts: $clientLimit,
            );

            if ($limited instanceof JsonResponse) {
                return $limited;
            }

            $nonceClaimed = Cache::add(
                'forms:external-intake:nonce:'.hash('sha256', $client->id."\n".strtolower($nonce)),
                true,
                $nonceTtl,
            );
        } catch (Throwable $exception) {
            return $this->authenticationUnavailable($request, $exception);
        }

        if (! $nonceClaimed) {
            return $this->error(
                request: $request,
                status: 409,
                code: 'request_replayed',
                message: 'This signed external intake request has already been received.',
            );
        }

        $request->attributes->set(self::CLIENT_ATTRIBUTE, $client);

        return $next($request);
    }

    private function rateLimit(
        Request $request,
        string $key,
        int $maximumAttempts,
    ): ?JsonResponse {
        $key = 'forms:external-intake:rate:'.$key;

        if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            return $this->error(
                request: $request,
                status: 429,
                code: 'rate_limited',
                message: 'Too many external form intake requests.',
                headers: ['Retry-After' => (string) $retryAfter],
            );
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function boundedInteger(
        string $key,
        int $minimum,
        int $maximum,
    ): ?int {
        $value = config("forms.external_intake.{$key}");

        return is_int($value) && $value >= $minimum && $value <= $maximum
            ? $value
            : null;
    }

    private function authenticationFailed(Request $request): JsonResponse
    {
        return $this->error(
            request: $request,
            status: 401,
            code: 'authentication_failed',
            message: 'External form intake authentication failed.',
        );
    }

    private function configurationUnavailable(
        Request $request,
        ?Throwable $exception = null,
    ): JsonResponse {
        Log::error('External Forms intake configuration is invalid.', [
            'exception' => $exception,
        ]);

        return $this->error(
            request: $request,
            status: 503,
            code: 'external_intake_unavailable',
            message: 'External form intake is temporarily unavailable.',
        );
    }

    private function authenticationUnavailable(
        Request $request,
        Throwable $exception,
    ): JsonResponse {
        Log::error('External Forms intake authentication state is unavailable.', [
            'exception' => $exception,
        ]);

        return $this->error(
            request: $request,
            status: 503,
            code: 'authentication_unavailable',
            message: 'External form intake authentication is temporarily unavailable.',
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $status, $headers);
    }
}