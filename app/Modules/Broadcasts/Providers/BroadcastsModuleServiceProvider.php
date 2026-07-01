<?php

namespace App\Modules\Broadcasts\Providers;

use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientFailed;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSent;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSkipped;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BroadcastsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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