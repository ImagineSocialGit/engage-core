<?php

use App\Modules\InboundMessaging\Controllers\CRM\ContactConversationReplyController;
use App\Modules\InboundMessaging\Controllers\CRM\InboundEmailRouteController;
use App\Modules\InboundMessaging\Controllers\CRM\InboundInboxController;
use App\Modules\InboundMessaging\Controllers\CRM\InboundReplyProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:inbound_messaging')
    ->prefix('inbox')
    ->name('crm.inbound-messaging.inbox.')
    ->group(function (): void {
        Route::get('/', [InboundInboxController::class, 'index'])
            ->name('index');

        Route::get('/{inboundMessage}', [InboundInboxController::class, 'show'])
            ->name('show');

        Route::patch('/{inboundMessage}/status', [InboundInboxController::class, 'state'])
            ->name('state');

        Route::patch('/{inboundMessage}/person', [InboundInboxController::class, 'link'])
            ->name('person.link');

        Route::delete('/{inboundMessage}/person', [InboundInboxController::class, 'unlink'])
            ->name('person.unlink');

        Route::post('/{inboundMessage}/person', [InboundInboxController::class, 'createContact'])
            ->name('person.create');
    });

Route::middleware('module:inbound_messaging')
    ->prefix('reply-handling/inbound-addresses')
    ->name('crm.inbound-messaging.email-routes.')
    ->group(function (): void {
        Route::get('/', [InboundEmailRouteController::class, 'index'])
            ->name('index');

        Route::post('/', [InboundEmailRouteController::class, 'store'])
            ->name('store');

        Route::patch('/{inboundEmailRoute}', [InboundEmailRouteController::class, 'update'])
            ->name('update');

        Route::patch('/{inboundEmailRoute}/state', [InboundEmailRouteController::class, 'state'])
            ->name('state');
    });

Route::middleware('module:inbound_messaging')
    ->prefix('reply-handling')
    ->name('crm.inbound-messaging.reply-profiles.')
    ->group(function (): void {
        Route::get('/', [InboundReplyProfileController::class, 'index'])
            ->name('index');

        Route::post('/', [InboundReplyProfileController::class, 'store'])
            ->name('store');

        Route::patch('/{inboundReplyProfile}', [InboundReplyProfileController::class, 'update'])
            ->name('update');

        Route::patch('/{inboundReplyProfile}/state', [InboundReplyProfileController::class, 'state'])
            ->name('state');

        Route::delete('/{inboundReplyProfile}', [InboundReplyProfileController::class, 'destroy'])
            ->name('destroy');
    });

Route::prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.')
    ->middleware('module:inbound_messaging')
    ->group(function () {
        Route::post('/{contact}/conversation/{inboundMessage}/reply', ContactConversationReplyController::class)
            ->name('conversation.reply.store');
    });