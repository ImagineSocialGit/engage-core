<?php

use App\Modules\Campaigns\Controllers\CRM\CampaignMessageTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module:campaigns', 'module:messaging'])
    ->prefix('campaigns/message-templates')
    ->name('crm.campaigns.message-templates.')
    ->group(function () {
        Route::get('/', [CampaignMessageTemplateController::class, 'index'])
            ->name('index');

        Route::patch('/{campaign}/deactivate', [CampaignMessageTemplateController::class, 'deactivate'])
            ->name('deactivate');

        Route::patch('/{campaign}/activate', [CampaignMessageTemplateController::class, 'activate'])
            ->name('activate');

        Route::patch('/steps/{campaignStep}', [CampaignMessageTemplateController::class, 'update'])
            ->name('update');
    });