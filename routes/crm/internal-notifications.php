<?php

use App\Modules\InternalNotifications\Controllers\CRM\InternalNotificationSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'module:internal_notifications',
    'capability:settings.manage',
])
    ->prefix('settings/team-notifications')
    ->name('crm.internal-notifications.settings.')
    ->group(function (): void {
        Route::get('/', [InternalNotificationSettingsController::class, 'index'])
            ->name('index');

        Route::post('/recipients', [InternalNotificationSettingsController::class, 'store'])
            ->name('recipients.store');

        Route::patch('/recipients/{teamMember}', [InternalNotificationSettingsController::class, 'update'])
            ->name('recipients.update');
    });