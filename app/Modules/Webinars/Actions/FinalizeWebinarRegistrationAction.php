<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Data\WebinarRegistrationResult;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\WebinarRegistration;
use Throwable;

class FinalizeWebinarRegistrationAction
{
    public function __construct(
        private readonly SyncWebinarRegistrationToProviderAction $syncToProvider,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchRegistrationMessages,
        private readonly DispatchConsentOptInMessageAction $dispatchConsentOptInMessage,
    ) {}

    public function handle(WebinarRegistrationResult $result): void
    {
        $registration = $result->registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]) ?? $result->registration;

        if ($result->wasExisting()) {
            $this->dispatchStandaloneConsentAcknowledgements(
                $registration->contact,
                $registration,
                $result->consentGrants,
            );

            return;
        }

        try {
            $syncResult = $this->syncToProvider->handle($registration);
        } catch (Throwable $exception) {
            report($exception);
            $syncResult = new WebinarProviderSyncResult(
                WebinarProviderSyncResult::STATUS_FAILED,
                $registration->webinar?->providerKey(),
            );
        }

        if ($syncResult->readyForRegistrationMessages()) {
            try {
                $this->dispatchRegistrationMessages->handle(
                    $registration->fresh([
                        'contact',
                        'webinar',
                        'webinar.webinarSeries',
                    ]) ?? $registration,
                    null,
                    $result->consentGrants,
                );

                return;
            } catch (Throwable $exception) {
                report($exception);
                // The retry job is safe here too: provider sync short-circuits
                // after success and Messaging dedupe prevents duplicate rows.
            }
        }

        SyncWebinarRegistrationToProviderJob::dispatch(
            (int) $registration->getKey(),
            $result->consentTransitionPayloads(),
        );
    }

    /**
     * @param array<int, MessageConsentGrantResult> $consentGrants
     */
    private function dispatchStandaloneConsentAcknowledgements(
        ?Contact $contact,
        WebinarRegistration $registration,
        array $consentGrants,
    ): void {
        if (! $contact instanceof Contact) {
            return;
        }

        foreach ($consentGrants as $grant) {
            if (! $grant->becameActive) {
                continue;
            }

            try {
                $this->dispatchConsentOptInMessage->handle(
                    contact: $contact,
                    grant: $grant,
                    payload: [
                        'webinar_registration_id' => $registration->getKey(),
                        'webinar_id' => $registration->webinar_id,
                        'webinar_slug' => $registration->webinar_slug,
                    ],
                    context: $registration,
                    resolverContext: [
                        'webinar_slug' => $registration->webinar_slug,
                    ],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}