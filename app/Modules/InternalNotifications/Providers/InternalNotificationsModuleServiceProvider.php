<?php

namespace App\Modules\InternalNotifications\Providers;

use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InternalNotifications\Listeners\ScheduleInboundMessageInternalNotification;
use App\Modules\InternalNotifications\Services\InternalNotificationChannelResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationPreferences\TeamMemberInternalNotificationPreferenceResolver;
use App\Modules\InternalNotifications\Services\Messaging\TeamMemberMessageRecipientGate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InternalNotificationsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            TeamMemberInternalNotificationPreferenceResolver::class,
        ], 'messaging.internal_notification_preference_resolvers');

        $this->app->when(InternalNotificationChannelResolver::class)
            ->needs('$preferenceResolvers')
            ->giveTagged('messaging.internal_notification_preference_resolvers');

        $this->app->tag([
            TeamMemberMessageRecipientGate::class,
        ], 'messaging.message_recipient_gates');
    }

    public function boot(): void
    {
        if (function_exists('module_enabled') && ! module_enabled('inbound_messaging')) {
            return;
        }

        Event::listen(
            InboundMessageReceived::class,
            ScheduleInboundMessageInternalNotification::class,
        );
    }
}