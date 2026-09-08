<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCrmUserActive
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->access->isActive($user)) {
            abort(403, 'This CRM account is inactive.');
        }

        return $next($request);
    }
}