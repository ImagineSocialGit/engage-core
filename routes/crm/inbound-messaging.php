<?php

use App\Modules\InboundMessaging\Controllers\CRM\ContactConversationReplyController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('contacts.routes.plural'))
    ->name('crm.contacts.')
    ->middleware('module:inbound_messaging')
    ->group(function () {
        Route::post('/{contact}/conversation/{inboundMessage}/reply', ContactConversationReplyController::class)
            ->name('conversation.reply.store');
    });