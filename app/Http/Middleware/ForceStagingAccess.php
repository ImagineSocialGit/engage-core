<?php

namespace App\Http\Middleware;

use App\Support\Staging\StagingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceStagingAccess
{
    /**
     * Externally authenticated provider callbacks and form transport only.
     * Do not use a webhooks.* wildcard: new routes must be reviewed.
     * The webhooks host is enforced by the route registration itself.
     *
     * @var array<string, list<string>>
     */
    private const EXTERNAL_ROUTES = [
        'webhooks.forms.show' => ['GET', 'HEAD'],
        'webhooks.forms.submissions.store' => ['POST'],
        'webhooks.webinar' => ['POST'],
        'webhooks.message-events.email' => ['POST'],
        'webhooks.message-events.sms' => ['POST'],
        'webhooks.inbound.sms' => ['POST'],
        'webhooks.inbound.email' => ['POST'],
        'webhooks.sms' => ['POST'],
        'webhooks.email' => ['POST'],
    ];

    public function __construct(private readonly StagingAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('staging')) {
            return $next($request);
        }

        if ($request->routeIs('staging.login', 'staging.login.submit')
            || $this->isExternalTransport($request)
        ) {
            return $this->privateResponse($next($request));
        }

        if (! $this->access->configured()) {
            return $this->privateResponse(response('Staging access unavailable.', 503));
        }

        if (! $this->access->authenticated($request)) {
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                return $this->privateResponse(response('Staging access required.', 403));
            }

            $this->access->rememberIntended($request);

            return $this->privateResponse(redirect('/staging-login'));
        }

        return $this->privateResponse($next($request));
    }

    private function isExternalTransport(Request $request): bool
    {
        $name = $request->route()?->getName();

        $webhooksHost = 'webhooks.'.(string) config('app.root_domain');

        return is_string($name)
            && hash_equals(strtolower($webhooksHost), strtolower($request->getHost()))
            && in_array($request->method(), self::EXTERNAL_ROUTES[$name] ?? [], true);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}