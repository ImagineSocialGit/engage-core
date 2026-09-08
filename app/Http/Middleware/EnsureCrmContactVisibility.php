<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCrmContactVisibility
{
    public function __construct(
        private readonly ContactVisibility $visibility,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Contact && ! $this->visibility->canView($user, $parameter)) {
                abort(404);
            }
        }

        return $next($request);
    }
}