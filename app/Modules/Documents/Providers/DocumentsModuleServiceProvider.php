<?php

namespace App\Modules\Documents\Providers;

use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\Documents\DocumentMessageAttachmentSource;
use Illuminate\Support\ServiceProvider;

class DocumentsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentAttachmentSource::class, DocumentAttachmentLibrary::class);
        $this->app->tag(DocumentMessageAttachmentSource::class, MessageAttachmentSource::TAG);
    }

    public function boot(): void
    {
        // Document files remain private; consuming modules use DocumentAttachmentSource.
    }
}