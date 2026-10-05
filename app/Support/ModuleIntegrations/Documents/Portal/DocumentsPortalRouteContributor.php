<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Portal\Contracts\PortalRouteContributor;
use App\Modules\Portal\Http\Middleware\RequireVerifiedPortalEmail;
use Illuminate\Support\Facades\Route;

final class DocumentsPortalRouteContributor implements PortalRouteContributor
{
    public function registerRoutes(): void
    {
        Route::middleware(RequireVerifiedPortalEmail::class)
            ->prefix('documents')
            ->name('documents.')
            ->group(function (): void {
                Route::get('/', [PortalDocumentController::class, 'index'])
                    ->name('index');

                Route::get('/requests/{documentRequest}', [PortalDocumentController::class, 'showRequest'])
                    ->whereNumber('documentRequest')
                    ->name('requests.show');

                Route::post('/requests/{documentRequest}/uploads', [PortalDocumentController::class, 'storeUpload'])
                    ->whereNumber('documentRequest')
                    ->name('requests.uploads.store');

                Route::get('/uploads/{documentUpload}/download', [PortalDocumentController::class, 'download'])
                    ->whereNumber('documentUpload')
                    ->name('uploads.download');
            });
    }
}