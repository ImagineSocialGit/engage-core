<?php

namespace App\Http\Middleware;

use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\HumanVerificationManager;
use App\Support\HumanVerification\PublicHumanVerificationGrantStore;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class RequirePublicHumanVerification
{
    public function __construct(
        private readonly HumanVerificationManager $verification,
        private readonly PublicHumanVerificationGrantStore $grants,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string $surface,
    ): Response {
        if (! $this->verification->enabledForSurface($surface)) {
            return $next($request);
        }

        if ($this->grants->hasValidGrant(
            session: $request->session(),
            surface: $surface,
            hostname: $request->getHost(),
        )) {
            $request->attributes->set(
                'public_human_verification',
                $this->grants->grantDetails($request->session(), $surface),
            );

            return $next($request);
        }

        $responseField = $this->verification->responseField();
        $result = $this->verification->verify(
            surface: $surface,
            token: $request->input($responseField),
            expectedHostnames: [$request->getHost()],
            remoteIp: $request->ip(),
        );

        if (! $result->passes()) {
            Log::warning('Public human verification failed.', [
                'surface' => $surface,
                'provider' => $result->provider,
                'hostname' => $request->getHost(),
                'reason' => $result->reason,
            ]);

            return $this->failedVerificationResponse(
                request: $request,
                responseField: $responseField,
                result: $result,
            );
        }

        $this->grants->grant(
            session: $request->session(),
            surface: $surface,
            hostname: $request->getHost(),
            result: $result,
            ttlSeconds: $this->verification->grantTtlSeconds(),
        );

        $request->request->remove($responseField);
        $request->attributes->set(
            'public_human_verification',
            $this->grants->grantDetails($request->session(), $surface),
        );

        Log::info('Public human verification passed.', [
            'surface' => $surface,
            'provider' => $result->provider,
            'hostname' => $result->hostname,
            'verified_at' => $result->verifiedAt?->format(DATE_ATOM),
        ]);

        return $next($request);
    }

    private function failedVerificationResponse(
        Request $request,
        string $responseField,
        HumanVerificationResult $result,
    ): RedirectResponse {
        $message = match ($result->reason) {
            HumanVerificationResult::REASON_UNAVAILABLE,
            HumanVerificationResult::REASON_CONFIGURATION =>
                'The security check is temporarily unavailable. Please try again shortly.',
            HumanVerificationResult::REASON_EXPIRED_OR_DUPLICATE =>
                'That security check expired. Please complete it again.',
            default =>
                'Please complete the security check before continuing.',
        };

        $request->request->remove($responseField);

        return back()
            ->withInput($request->except($responseField))
            ->withErrors([
                'human_verification' => $message,
            ]);
    }
}