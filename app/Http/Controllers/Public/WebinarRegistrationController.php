<?php

namespace App\Http\Controllers\Public;

use App\Actions\Webinars\CreateWebinarRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreWebinarRegistrationRequest;
use App\Jobs\Messaging\SendWebinarConfirmationEmail;
use App\Jobs\Webinars\ProcessWebinarRegistration;
use App\Models\Webinar;

class WebinarRegistrationController extends Controller
{
    public function create()
    {
        return view('webinar.register');
    }

    public function show(Webinar $webinar)
    {
        return view('webinar.register', compact('webinar'));
    }

    public function store(
        StoreWebinarRegistrationRequest $request,
        Webinar $webinar,
        CreateWebinarRegistration $createWebinarRegistration
    ) {
        $registration = $createWebinarRegistration->handle(
            $request->validated(),
            $request,
            $webinar->slug
        );

        ProcessWebinarRegistration::dispatch($registration->id);
        SendWebinarConfirmationEmail::dispatch($registration->id);

        return redirect('/' . $webinar->slug . '/thank-you');
    }
}