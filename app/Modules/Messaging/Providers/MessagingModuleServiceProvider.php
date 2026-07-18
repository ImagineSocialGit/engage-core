<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Automation\MessagingAutomationPointAuthoringContributor;
use App\Modules\Messaging\Automation\MessagingAutomationPointDefinitionContributor;
use App\Modules\Messaging\Automation\SendMessageAutomationActionHandler;
use App\Modules\Messaging\Capabilities\MessagingAutomationCapabilityContributor;
use App\Modules\Messaging\ConfigContracts\EmailMessageDefinitionConfigContract;
use App\Modules\Messaging\ConfigContracts\MessagingConfigContractTargetProvider;
use App\Modules\Messaging\ConfigContracts\PermissionInvitationConfigContract;
use App\Modules\Messaging\ConfigContracts\SmsMessageDefinitionConfigContract;
use App\Modules\Messaging\Console\Commands\SyncMessageTemplatePresetsCommand;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Jobs\PublishScheduledMessageOutboxEventsJob;
use App\Modules\Messaging\Jobs\RecoverStaleScheduledMessageClaimsJob;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationFailedAfterScheduledMessageFailed;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkipped;
use App\Modules\Messaging\Listeners\MarkClaimedPermissionInvitationSentAfterScheduledMessageSent;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactShow\ContactMessagingShowDataProvider;
use App\Modules\Messaging\Services\ContactShow\ContactScheduledMessagesVisibilityDataProvider;
use App\Modules\Messaging\Services\Email\EmailProviderManager;
use App\Modules\Messaging\Services\MessageRecipientGateRegistry;
use App\Modules\Messaging\Services\MessageRecipientPayloadProviderRegistry;
use App\Modules\Messaging\Services\Sms\SmsProviderManager;
use App\Modules\Messaging\TokenContracts\MessagingTokenContextProvider;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class MessagingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('messaging/sms.php'), 'messaging.sms');
        $this->mergeConfigFrom(config_path('messaging/email.php'), 'messaging.email');

        $this->app->tag([
            EmailMessageDefinitionConfigContract::class,
            SmsMessageDefinitionConfigContract::class,
            PermissionInvitationConfigContract::class,
        ], 'config.contracts');

        $this->app->tag(
            MessagingConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );

        $this->app->tag(
            MessagingTokenContextProvider::class,
            'token.context_providers',
        );

        $this->app->tag([
            MessagingAutomationCapabilityContributor::class,
        ], 'automation.capability_contributors');

        $this->app->tag([
            MessagingAutomationPointDefinitionContributor::class,
        ], 'automation.point_definition_contributors');

        $this->app->tag([
            MessagingAutomationPointAuthoringContributor::class,
        ], 'automation.point_authoring_contributors');

        $this->app->tag([
            SendMessageAutomationActionHandler::class,
        ], 'automation.action_handlers');

        $this->app->tag([
            MessagingSetupValidationContributor::class,
        ], 'setup.validation_contributors');

        $this->app->singleton(Client::class, function () {
            return new Client(
                config('services.twilio.sid'),
                config('services.twilio.token'),
            );
        });

        $this->app->singleton(SmsProviderManager::class, function () {
            return SmsProviderManager::default();
        });

        $this->app->singleton(EmailProviderManager::class);

        $this->app->singleton(MessageRecipientGateRegistry::class, function ($app) {
            return new MessageRecipientGateRegistry(
                gates: $app->tagged('messaging.message_recipient_gates'),
            );
        });

        $this->app->singleton(MessageRecipientPayloadProviderRegistry::class, function ($app) {
            return new MessageRecipientPayloadProviderRegistry(
                providers: $app->tagged('messaging.message_recipient_payload_providers'),
            );
        });

        $this->app->tag([
            ContactMessagingShowDataProvider::class,
            ContactScheduledMessagesVisibilityDataProvider::class,
        ], 'core.contact_show_data_providers');
    }

    public function boot(): void
    {
        $this->callAfterResolving(
            Schedule::class,
            function (Schedule $schedule): void {
                $schedule
                    ->job(new RecoverStaleScheduledMessageClaimsJob())
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule
                    ->job(new PublishScheduledMessageOutboxEventsJob())
                    ->everyMinute()
                    ->withoutOverlapping();
            },
        );

        Event::listen(
            ScheduledMessageSent::class,
            MarkClaimedPermissionInvitationSentAfterScheduledMessageSent::class,
        );

        Event::listen(
            ScheduledMessageSkipped::class,
            MarkClaimedPermissionInvitationFailedAfterScheduledMessageSkipped::class,
        );

        Event::listen(
            ScheduledMessageFailed::class,
            MarkClaimedPermissionInvitationFailedAfterScheduledMessageFailed::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncMessageTemplatePresetsCommand::class,
            ]);
        }

        Contact::resolveRelationUsing('messageConsents', function (Contact $contact): HasMany {
            return $contact->hasMany(MessageConsent::class);
        });

        Contact::resolveRelationUsing('permissionInvitations', function (Contact $contact): HasMany {
            return $contact->hasMany(ContactPermissionInvitation::class);
        });

        Contact::resolveRelationUsing('scheduledMessages', function (Contact $contact): HasMany {
            return $contact->hasMany(ScheduledMessage::class, 'recipient_id')
                ->where('recipient_type', $contact->getMorphClass());
        });
    }
}