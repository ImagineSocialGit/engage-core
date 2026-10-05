<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Portal\Services\PortalAuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireVerifiedPortalEmail
{
    public function __construct(private readonly PortalAuthContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->requireUser()->hasVerifiedEmail()) {
            return redirect()->route('portal.account.show');
        }

        return $next($request);
    }
}