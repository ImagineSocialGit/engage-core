<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceStagingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('staging')) {
            return $next($request);
        }

        if (
            $request->is('staging-login') ||
            $request->routeIs('webhooks.*')
        ) {
            return $next($request);
        }

        if (session('staging_access') === true) {
            return $next($request);
        }

        return redirect()->to('/staging-login');
    }
}