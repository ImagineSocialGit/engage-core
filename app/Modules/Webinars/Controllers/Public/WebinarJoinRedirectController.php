<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Http\Controllers\Controller;
use App\Modules\Webinars\Models\WebinarRegistration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebinarJoinRedirectController extends Controller
{
    public function __invoke(
        string $token,
        ResolveWebinarJoinUrlAction $resolveWebinarJoinUrlAction
    ) {
        $registration = WebinarRegistration::query()
            ->with('webinar')
            ->where('join_token', $token)
            ->firstOrFail();

        $destination = $resolveWebinarJoinUrlAction->execute($registration);

        if (blank($destination)) {
            throw new NotFoundHttpException('No join URL is available for this registration.');
        }

        return redirect()->away($destination);
    }
}
