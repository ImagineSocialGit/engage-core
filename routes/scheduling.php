<?php

use App\Modules\Scheduling\Controllers\PublicBookingController;
use Illuminate\Support\Facades\Route;

$reservationLimit = max(
    1,
    (int) config('scheduling.public.reservation_rate_limit_per_minute', 12),
);
$holdReviewLimit = max(
    1,
    (int) config('scheduling.public.hold_review_rate_limit_per_minute', 60),
);

Route::get('/', [PublicBookingController::class, 'index'])
    ->name('scheduling.public.index');

Route::get('/services/{serviceKey}', [PublicBookingController::class, 'show'])
    ->where('serviceKey', '[A-Za-z0-9][A-Za-z0-9_-]*')
    ->name('scheduling.public.services.show');

Route::post('/services/{serviceKey}/reserve', [PublicBookingController::class, 'reserve'])
    ->where('serviceKey', '[A-Za-z0-9][A-Za-z0-9_-]*')
    ->middleware("throttle:{$reservationLimit},1")
    ->name('scheduling.public.services.reserve');

Route::get('/book/{holdId}', [PublicBookingController::class, 'review'])
    ->whereUuid('holdId')
    ->middleware("throttle:{$holdReviewLimit},1")
    ->name('scheduling.public.holds.show');