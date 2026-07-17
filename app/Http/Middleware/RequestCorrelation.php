<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelation
{
    private const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set('request_id', $requestId);

        Log::withContext([
            'request_id' => $requestId,
            'request_host' => $request->getHost(),
            'request_method' => $request->getMethod(),
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function requestId(Request $request): string
    {
        $incoming = trim((string) $request->headers->get(self::HEADER, ''));

        if (
            $incoming !== ''
            && strlen($incoming) <= 128
            && preg_match('/^[A-Za-z0-9._-]+$/', $incoming) === 1
        ) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
