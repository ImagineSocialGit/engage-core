<?php

namespace App\Modules\Broadcasts\Providers;

use App\Modules\Broadcasts\Access\BroadcastsAccessCapabilityContributor;
use App\Modules\Broadcasts\Contacts\BroadcastContactResultActionContributor;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientFailed;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSent;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSkipped;
use App\Modules\Broadcasts\Messaging\BroadcastReusableMessageTemplateAuthoringContributor;
use App\Modules\Broadcasts\TokenContracts\BroadcastTokenContextProvider;
use App\Modules\Core\Access\Support\AccessCapabilityRegistry;
use App\Modules\Core\Support\Contacts\ContactResultActionRegistry;
use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BroadcastsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            BroadcastsAccessCapabilityContributor::class,
            AccessCapabilityRegistry::CONTRIBUTOR_TAG,
        );

        $this->app->tag(
            BroadcastContactResultActionContributor::class,
            ContactResultActionRegistry::CONTRIBUTOR_TAG,
        );

        $this->app->tag(
            BroadcastTokenContextProvider::class,
            'token.context_providers',
        );

        $this->app->tag(
            BroadcastReusableMessageTemplateAuthoringContributor::class,
            ReusableMessageTemplateAuthoringOptionContributor::TAG,
        );
    }

    public function boot(): void
    {
        Event::listen(
            ScheduledMessageSent::class,
            MarkBroadcastRecipientSent::class,
        );

        Event::listen(
            ScheduledMessageSkipped::class,
            MarkBroadcastRecipientSkipped::class,
        );

        Event::listen(
            ScheduledMessageFailed::class,
            MarkBroadcastRecipientFailed::class,
        );
    }
}