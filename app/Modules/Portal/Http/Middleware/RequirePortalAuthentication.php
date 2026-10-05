<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Portal\Models\PortalUser;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePortalAuthentication
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('portal');
        $user = $guard->user();

        if (! $user instanceof PortalUser || ! $user->isAuthenticatable()) {
            $guard->logout();

            return redirect()->guest(route('portal.login'));
        }

        return $next($request);
    }
}