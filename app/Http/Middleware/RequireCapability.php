<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCapability
{
    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->access->allows($user, $capability)) {
            abort(403);
        }

        return $next($request);
    }
}