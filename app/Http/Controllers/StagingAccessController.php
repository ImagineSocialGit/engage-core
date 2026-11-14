<?php

namespace App\Http\Controllers;

use App\Support\Staging\StagingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** No CRM/database user is created or authenticated by the staging gate. */
final class StagingAccessController
{
    public function show(Request $request, StagingAccess $access): View|Response
    {
        abort_unless(app()->environment('staging'), 404);
        if (! $access->configured()) {
            return response('Staging access unavailable.', 503);
        }

        return view('staging.login', [
            'isAuthenticated' => $access->authenticated($request),
        ]);
    }

    public function store(Request $request, StagingAccess $access): RedirectResponse|Response
    {
        abort_unless(app()->environment('staging'), 404);
        if (! $access->configured()) {
            return response('Staging access unavailable.', 503);
        }

        if (! $access->credentialsMatch($request->input('user'), $request->input('password'))) {
            return redirect('/staging-login')->withErrors([
                'login' => 'Invalid credentials.',
            ]);
        }

        $access->logIn($request);

        return redirect()->to($access->takeIntended($request));
    }

    public function destroy(Request $request, StagingAccess $access): RedirectResponse
    {
        abort_unless(app()->environment('staging'), 404);

        $access->logOut($request);

        return redirect('/staging-login');
    }
}