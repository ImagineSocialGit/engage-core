<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    require __DIR__.'/crm/core.php';
    require __DIR__.'/crm/forms.php';
    require __DIR__.'/crm/media.php';
    require __DIR__.'/crm/relationships.php';
    require __DIR__.'/crm/webinars.php';
    require __DIR__.'/crm/campaigns.php';
    require __DIR__.'/crm/messaging.php';
    require __DIR__.'/crm/inbound-messaging.php';
    require __DIR__.'/crm/reporting.php';
    require __DIR__.'/crm/flow-routes.php';
    require __DIR__.'/crm/broadcasts.php';
    require __DIR__.'/crm/scheduling.php';
    require __DIR__.'/crm/tasks.php';
});