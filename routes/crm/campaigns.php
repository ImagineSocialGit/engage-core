<?php

use App\Modules\Campaigns\Controllers\CRM\CampaignAnnualTouchController;
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

        Route::prefix('annual-touches')
            ->name('annual-touches.')
            ->group(function (): void {
                Route::get('/', [CampaignAnnualTouchController::class, 'index'])
                    ->name('index');
                Route::post('/', [CampaignAnnualTouchController::class, 'store'])
                    ->name('store');
                Route::post('/message-templates', [CampaignAnnualTouchController::class, 'storeMessageTemplate'])
                    ->name('message-templates.store');
                Route::post('/audience-preview', [CampaignAnnualTouchController::class, 'previewAudience'])
                    ->name('audience-preview');
                Route::put('/{campaignTouchProgram}', [CampaignAnnualTouchController::class, 'update'])
                    ->name('update');
                Route::delete('/{campaignTouchProgram}', [CampaignAnnualTouchController::class, 'destroy'])
                    ->name('destroy');
            });

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

        Route::post('/{campaign}/eligibility/preview', [CampaignController::class, 'previewEligibility'])
            ->name('eligibility.preview');

        Route::patch('/{campaign}/eligibility', [CampaignController::class, 'updateEligibility'])
            ->name('eligibility.update');

        Route::patch('/{campaign}/schedule', [CampaignController::class, 'updateSchedule'])
            ->name('schedule.update');

        Route::patch('/{campaign}/messages/{messageChainStepVariant}/reply-handling', [CampaignController::class, 'updateMessageReplyHandling'])
            ->name('messages.reply-handling.update');

        Route::patch('/{campaign}/messages/{messageChainStepVariant}/{messageTemplatePreset}', [CampaignController::class, 'updateMessage'])
            ->name('messages.update');

        Route::patch('/{campaign}/deactivate', [CampaignController::class, 'deactivate'])
            ->name('deactivate');

        Route::patch('/{campaign}/activate', [CampaignController::class, 'activate'])
            ->name('activate');

        Route::get('/{campaign}', [CampaignController::class, 'show'])
            ->name('show');
    });