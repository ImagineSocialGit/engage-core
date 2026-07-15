<?php

namespace App\Modules\InternalNotifications\Providers;

use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InternalNotifications\Listeners\ScheduleInboundMessageInternalNotification;
use App\Modules\InternalNotifications\Services\InternalNotificationChannelResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationPreferences\TeamMemberInternalNotificationPreferenceResolver;
use App\Modules\InternalNotifications\Services\Messaging\TeamMemberMessageRecipientGate;
use App\Modules\InternalNotifications\Services\Messaging\TeamMemberMessageRecipientPayloadProvider;
use App\Modules\InternalNotifications\Services\Tasks\InternalNotificationTaskScheduler;
use App\Modules\InternalNotifications\Services\Tasks\OnlyActiveTeamMemberTaskAssignmentStrategyResolver;
use App\Modules\InternalNotifications\Services\Tasks\TeamMemberTaskAssignedRecipientResolver;
use App\Modules\InternalNotifications\Services\Tasks\TeamMemberTaskAssigneeOptionProvider;
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

        $this->app->tag([
            TeamMemberMessageRecipientPayloadProvider::class,
        ], 'messaging.message_recipient_payload_providers');

        $this->registerTaskIntegrations();
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

    private function registerTaskIntegrations(): void
    {
        if (function_exists('module_enabled') && ! module_enabled('tasks')) {
            return;
        }

        $this->app->tag([
            OnlyActiveTeamMemberTaskAssignmentStrategyResolver::class,
        ], 'tasks.assignment_strategy_resolvers');

        $this->app->tag([
            TeamMemberTaskAssignedRecipientResolver::class,
        ], 'crm.tasks.assigned_recipient_resolvers');

        $this->app->tag([
            TeamMemberTaskAssigneeOptionProvider::class,
        ], 'tasks.assignee_option_providers');

        $this->app->tag([
            InternalNotificationTaskScheduler::class,
        ], 'tasks.notification_schedulers');
    }
}
