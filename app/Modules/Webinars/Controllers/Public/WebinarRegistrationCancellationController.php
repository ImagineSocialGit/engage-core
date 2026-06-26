<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Http\Controllers\Controller;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Response;

class WebinarRegistrationCancellationController extends Controller
{
    public function __invoke(
        WebinarRegistration $registration,
        CancelWebinarRegistrationAction $cancelWebinarRegistrationAction
    ): Response {
        $registration = $cancelWebinarRegistrationAction->handle($registration);

        return response()->view('webinar.registration-cancelled', [
            'registration' => $registration,
        ]);
    }
}