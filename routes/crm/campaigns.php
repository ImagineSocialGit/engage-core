<?php

use App\Modules\Campaigns\Controllers\CRM\CampaignController;
use App\Modules\Campaigns\Controllers\CRM\CampaignMessageTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('module:campaigns')
    ->prefix('campaigns')
    ->name('crm.campaigns.')
    ->group(function () {
        Route::get('/', [CampaignController::class, 'index'])
            ->name('index');

        Route::prefix('message-templates')
            ->name('message-templates.')
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

        Route::get('/{campaign}/edit', [CampaignController::class, 'edit'])
            ->name('edit');

        Route::patch('/{campaign}/deactivate', [CampaignController::class, 'deactivate'])
            ->name('deactivate');

        Route::patch('/{campaign}/activate', [CampaignController::class, 'activate'])
            ->name('activate');

        Route::get('/{campaign}', [CampaignController::class, 'show'])
            ->name('show');
    });