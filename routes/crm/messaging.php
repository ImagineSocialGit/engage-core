<?php

use App\Modules\Messaging\Controllers\CRM\CreateFlowRouteMessageTemplateController;
use App\Modules\Messaging\Controllers\CRM\CreateReusableMessageTemplateController;
use App\Modules\Messaging\Controllers\CRM\DeleteMessageTemplatePresetController;
use App\Modules\Messaging\Controllers\CRM\ContactDirectMessageController;
use App\Modules\Messaging\Controllers\CRM\MessageDeliveryIssueController;
use App\Modules\Messaging\Controllers\CRM\MessageTemplatePresetController;
use App\Modules\Messaging\Controllers\CRM\OutboundMessageController;
use App\Modules\Messaging\Controllers\CRM\BulkOutboundMessageController;
use Illuminate\Support\Facades\Route;


Route::middleware('module:messaging')
    ->prefix('settings/outbound-messages')
    ->name('crm.messaging.outbound.')
    ->group(function () {
        Route::get('/', [OutboundMessageController::class, 'index'])->name('index');
        Route::get('/bulk', [BulkOutboundMessageController::class, 'index'])
            ->middleware(['capability:contacts.manage', 'capability:contacts.view_all'])
            ->name('bulk.index');
        Route::post('/bulk', [BulkOutboundMessageController::class, 'save'])
            ->middleware(['capability:contacts.manage', 'capability:contacts.view_all'])
            ->name('bulk.save');
        Route::post('/contact-group', [OutboundMessageController::class, 'contactGroup'])
            ->name('contact-group');
        Route::post('/{scheduledMessage}/content', [OutboundMessageController::class, 'editContent'])
            ->middleware('capability:contacts.manage')
            ->name('content');
        Route::post('/{scheduledMessage}', [OutboundMessageController::class, 'control'])
            ->middleware('capability:contacts.manage')
            ->name('control');
    });

Route::middleware('module:messaging')
    ->prefix(config('contacts.routes.plural'))
    ->name('crm.messaging.contacts.')
    ->group(function () {
        Route::post('/{contact}/messages', [ContactDirectMessageController::class, 'store'])
            ->name('messages.store');
    });

Route::middleware('module:messaging')
    ->prefix('messaging/delivery-issues')
    ->name('crm.messaging.delivery-issues.')
    ->group(function () {
        Route::get('/', [MessageDeliveryIssueController::class, 'index'])
            ->name('index');

        Route::post('/{messageSuppression}/release', [MessageDeliveryIssueController::class, 'release'])
            ->name('release');

        Route::post('/{messageSuppression}/dismiss', [MessageDeliveryIssueController::class, 'dismiss'])
            ->name('dismiss');
    });

Route::middleware('module:messaging')
    ->prefix('message-templates')
    ->name('crm.messaging.message-templates.')
    ->group(function () {
        Route::get('/', [MessageTemplatePresetController::class, 'index'])
            ->name('index');

        Route::get('/create', [CreateReusableMessageTemplateController::class, 'create'])
            ->name('create');

        Route::post('/reusable', [CreateReusableMessageTemplateController::class, 'store'])
            ->name('store');

        Route::post('/reusable/flow-route', CreateFlowRouteMessageTemplateController::class)
            ->middleware('module:flow_routes')
            ->name('flow-route.store');

        Route::patch(
            '/composition-layers/{messageTemplateCompositionLayer}',
            [MessageTemplatePresetController::class, 'updateCompositionLayer'],
        )->name('composition-layers.update');

        Route::patch('/{messageTemplatePreset}', [MessageTemplatePresetController::class, 'update'])
            ->name('update');

        Route::delete('/{messageTemplatePreset}', DeleteMessageTemplatePresetController::class)
            ->name('destroy');
    });