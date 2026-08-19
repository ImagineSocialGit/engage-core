<?php

use App\Modules\Campaigns\Controllers\CRM\CampaignController;
use App\Modules\Campaigns\Controllers\CRM\CampaignMessageTemplateController;
use App\Modules\Campaigns\Controllers\CRM\CampaignSimulatorController;
use App\Support\TestingTools\TestingToolGuard;
use Illuminate\Support\Facades\Route;

Route::middleware('module:campaigns')
    ->prefix('campaigns')
    ->name('crm.campaigns.')
    ->group(function () {
        Route::get('/', [CampaignController::class, 'index'])
            ->name('index');

        if (app(TestingToolGuard::class)->routesMayRegister()) {
            Route::prefix('testing/simulator')
                ->name('simulator.')
                ->group(function (): void {
                    Route::get('/', [CampaignSimulatorController::class, 'index'])
                        ->name('index');
                    Route::post('/', [CampaignSimulatorController::class, 'store'])
                        ->name('store');
                    Route::get('/{simulation}', [CampaignSimulatorController::class, 'show'])
                        ->name('show');
                    Route::post('/{simulation}/process', [CampaignSimulatorController::class, 'process'])
                        ->name('process');
                    Route::post('/{simulation}/advance', [CampaignSimulatorController::class, 'advance'])
                        ->name('advance');
                    Route::delete('/{simulation}', [CampaignSimulatorController::class, 'destroy'])
                        ->name('destroy');
                });
        }

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