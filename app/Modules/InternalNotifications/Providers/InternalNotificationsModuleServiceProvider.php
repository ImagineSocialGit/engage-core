<?php

namespace App\Modules\InternalNotifications\Providers;

use App\Modules\InternalNotifications\Services\InternalNotificationChannelResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationPreferences\TeamMemberInternalNotificationPreferenceResolver;
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
    }

    public function boot(): void
    {
        //
    }
}