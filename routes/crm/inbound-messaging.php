<?php

use App\Modules\InboundMessaging\Controllers\CRM\ContactConversationReplyController;
use App\Modules\InboundMessaging\Controllers\CRM\InboundReplyProfileController;
use Illuminate\Support\Facades\Route;

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