<?php

use App\Modules\Forms\Controllers\Public\HostedFormController;
use Illuminate\Support\Facades\Route;

$submissionLimit = max(
    1,
    (int) config('forms.public.submission_rate_limit_per_minute', 20),
);

Route::middleware('module:forms')->group(function () use ($submissionLimit): void {
    Route::get('/{formSlug}', [HostedFormController::class, 'show'])
        ->where('formSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('forms.public.show');

    Route::post('/{formSlug}', [HostedFormController::class, 'store'])
        ->where('formSlug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware([
            "throttle:{$submissionLimit},1",
            'public-human:forms',
        ])
        ->name('forms.public.store');
});