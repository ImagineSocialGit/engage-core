<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Data\WebinarRegistrationConsentTransition;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Data\WebinarRegistrationResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class CreateWebinarRegistrationAction
{
    private const MAX_CREATE_ATTEMPTS = 2;

    public function __construct(
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly GrantMessageConsentsAction $grantMessageConsentsAction,
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly WebinarRegisterPageConfig $registerPageConfig,
        private readonly WebinarRegistrationQuestionResolver $questionResolver,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly FinalizeWebinarRegistrationAction $finalizeRegistration,
        private readonly QueueWebinarRegistrationFinalizationAction $queueFinalization,
    ) {}

    public function handle(
        array $validated,
        Request $request,
        Webinar $webinar,
    ): WebinarRegistrationResult {
        $result = null;

        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                $result = DB::transaction(fn (): WebinarRegistrationResult => $this->createOrResolve(
                    validated: $validated,
                    request: $request,
                    webinar: $webinar,
                ));

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MAX_CREATE_ATTEMPTS) {
                    throw $exception;
                }

                // A concurrent request may have committed the Contact or
                // WebinarRegistration first. Retry once and resolve that row
                // through the normal idempotent path.
            }
        }

        if (! $result instanceof WebinarRegistrationResult) {
            throw new LogicException('Webinar registration could not be resolved.');
        }

        if ($result->wasExisting()) {
            try {
                $finalization = $this->finalizeRegistration->handle($result);

                if ($finalization?->shouldRetry()) {
                    $this->queueFinalization->handle($result->registration);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->safelyQueueFinalization($result->registration);
            }

            return $result;
        }

        $this->safelyQueueFinalization($result->registration);

        return $result;
    }

    private function createOrResolve(
        array $validated,
        Request $request,
        Webinar $webinar,
    ): WebinarRegistrationResult {
        $email = strtolower(trim((string) $validated['email']));
        $normalizedPhone = $this->phoneNumberNormalizer->normalize(
            $validated['phone'] ?? null,
        );

        $existingContact = Contact::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->lockForUpdate()
            ->first();

        $contactData = [
            'email' => $email,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? null,
            'phone' => $normalizedPhone,
        ];

        if (! $existingContact instanceof Contact) {
            $contactData['source'] = 'webinar';
            $contactData['subsource'] = $webinar->slug;
        }

        $contact = $this->createOrUpdateContact->handle(
            data: $contactData,
        );

        $registration = WebinarRegistration::query()
            ->where('contact_id', $contact->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->lockForUpdate()
            ->first();

        if ($registration instanceof WebinarRegistration) {
            $this->storeRegistrationResponses(
                validated: $validated,
                webinar: $webinar,
                registration: $registration,
            );

            $result = WebinarRegistrationResult::existing(
                registration: $registration,
                consentGrants: $this->storeMessageConsents(
                    validated: $validated,
                    request: $request,
                    contact: $contact,
                    registration: $registration,
                ),
            );

            $this->stageFinalization($result);

            return $result;
        }

        $now = now();

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'webinar_subdomain',
            'registered_at' => $now,
            'attended_at' => null,
            'meta' => [
                // Consent/business provenance. Reporting must not reuse this
                // as anonymous visitor identity.
                'request_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'accepted_channels' => [
                    'transactional' => $this->acceptedChannels(
                        validated: $validated,
                        purpose: MessagePurpose::Transactional,
                        scope: 'webinar',
                    ),
                    'marketing' => $this->acceptedChannels(
                        validated: $validated,
                        purpose: MessagePurpose::Marketing,
                        scope: 'webinar_nurture',
                    ),
                ],
            ],
        ]);

        $this->storeRegistrationResponses(
            validated: $validated,
            webinar: $webinar,
            registration: $registration,
        );

        $consentGrants = $this->storeMessageConsents(
            validated: $validated,
            request: $request,
            contact: $contact,
            registration: $registration,
            now: $now,
        );

        $registration->load([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        $this->emitWebinarAutomationEvent->forRegistration(
            eventKey: 'webinar.registered',
            registration: $registration,
            occurredAt: $registration->registered_at ?? $now,
        );

        $result = WebinarRegistrationResult::created(
            registration: $registration,
            consentGrants: $consentGrants,
        );

        $this->stageFinalization($result);

        return $result;
    }

    private function storeRegistrationResponses(
        array $validated,
        Webinar $webinar,
        WebinarRegistration $registration,
    ): void {
        $submittedAnswers = $validated['registration_questions'] ?? null;

        if ($submittedAnswers === null) {
            return;
        }

        if (! is_array($submittedAnswers)) {
            throw new LogicException(
                'Validated Webinar registration questions must be an array.',
            );
        }

        $webinar->loadMissing('webinarSeries');
        $series = $webinar->webinarSeries;

        if (! $series) {
            throw new LogicException(
                'Webinar registration questions require a Webinar series.',
            );
        }

        $content = $this->registerPageConfig->content(
            page: 'register',
            seriesSlug: $series->slug,
            seriesMeta: is_array($series->meta) ? $series->meta : [],
        );
        $questions = $this->questionResolver->resolve(
            data_get($content, 'registration.questions', []),
        );
        $snapshots = $this->questionResolver->responseSnapshots(
            questions: $questions,
            submittedAnswers: $submittedAnswers,
        );

        foreach ($snapshots as $snapshot) {
            $registration->responses()->updateOrCreate(
                [
                    'question_key' => $snapshot['question_key'],
                ],
                $snapshot,
            );
        }
    }

    private function stageFinalization(
        WebinarRegistrationResult $result,
    ): void {
        $registration = $result->registration;
        $meta = is_array($registration->meta) ? $registration->meta : [];
        $existingState = is_array(
            $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
        )
            ? $meta[WebinarRegistrationFinalizationResult::META_KEY]
            : [];
        $transitions = $this->activeConsentTransitions($result);
        $stagedAt = now()->toISOString();

        if ($result->wasCreated()) {
            $meta[WebinarRegistrationFinalizationResult::META_KEY] = [
                'status' => 'pending',
                'mode' => 'initial_registration',
                'consent_transitions' => $transitions,
                'attempts' => 0,
                'queue_dispatch_attempts' => 0,
                'staged_at' => $stagedAt,
                'last_state_changed_at' => $stagedAt,
                'failure_reason' => null,
            ];

            $registration->forceFill(['meta' => $meta])->save();

            return;
        }

        if ($transitions === []) {
            return;
        }

        $existingStatus = (string) ($existingState['status'] ?? '');
        $existingMode = (string) ($existingState['mode'] ?? '');

        if (
            $existingMode === 'initial_registration'
            && $existingStatus !== 'completed'
        ) {
            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $existingState,
                [
                    'consent_transitions' => $this->mergeConsentTransitions(
                        is_array($existingState['consent_transitions'] ?? null)
                            ? $existingState['consent_transitions']
                            : [],
                        $transitions,
                    ),
                    'last_state_changed_at' => $stagedAt,
                ],
            );

            $registration->forceFill(['meta' => $meta])->save();

            return;
        }

        $meta[WebinarRegistrationFinalizationResult::META_KEY] = [
            'status' => 'pending',
            'mode' => 'consent_acknowledgements',
            'consent_transitions' => $transitions,
            'attempts' => 0,
            'queue_dispatch_attempts' => 0,
            'staged_at' => $stagedAt,
            'last_state_changed_at' => $stagedAt,
            'failure_reason' => null,
            'initial_completed_at' => $existingMode === 'initial_registration'
                ? ($existingState['completed_at'] ?? null)
                : ($existingState['initial_completed_at'] ?? null),
        ];

        $registration->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    private function activeConsentTransitions(
        WebinarRegistrationResult $result,
    ): array {
        return array_values(array_map(
            static fn (MessageConsentGrantResult $grant): array =>
                WebinarRegistrationConsentTransition::fromGrant($grant)->toArray(),
            array_values(array_filter(
                $result->consentGrants,
                static fn (mixed $grant): bool =>
                    $grant instanceof MessageConsentGrantResult
                    && $grant->becameActive,
            )),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeConsentTransitions(
        array $existing,
        array $incoming,
    ): array {
        $merged = [];

        foreach ([...$existing, ...$incoming] as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $consentId = (int) ($transition['consent_id'] ?? 0);

            if ($consentId <= 0) {
                continue;
            }

            $merged[$consentId] = $transition;
        }

        return array_values($merged);
    }

    private function safelyQueueFinalization(
        WebinarRegistration $registration,
    ): void {
        try {
            $this->queueFinalization->handle($registration);
        } catch (Throwable $exception) {
            // The durable pending state was committed with the registration.
            // The scheduled recovery pass can retry this handoff later.
            report($exception);
        }
    }

    /**
     * @return array<int, MessageConsentGrantResult>
     */
    private function storeMessageConsents(
        array $validated,
        Request $request,
        Contact $contact,
        WebinarRegistration $registration,
        mixed $now = null,
    ): array {
        $now ??= now();
        $grants = [];

        foreach ($this->consentDefinitions() as $field => $definition) {
            if (! ($validated[$field] ?? false)) {
                continue;
            }

            if (! $this->messageChannelAvailability->isVisibleForSurface(
                channel: $definition['channel'],
                surface: 'webinar_registrations',
                purpose: $definition['purpose']->value,
                scope: $definition['scope'],
            )) {
                continue;
            }

            $grants[] = [
                'channel' => $definition['channel']->value,
                'purpose' => $definition['purpose']->value,
                'scope' => $definition['scope'],
                'consented_at' => $now,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_registration',
                'meta' => [
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
            ];
        }

        return $this->grantMessageConsentsAction->handle(
            contact: $contact,
            grants: $grants,
            context: $registration,
        );
    }

    /**
     * @return array<string, array{channel: MessageChannel, purpose: MessagePurpose, scope: string}>
     */
    private function consentDefinitions(): array
    {
        return [
            'transactional_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Transactional,
                'scope' => 'webinar',
            ],
            'transactional_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Transactional,
                'scope' => 'webinar',
            ],
            'marketing_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Marketing,
                'scope' => 'webinar_nurture',
            ],
            'marketing_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Marketing,
                'scope' => 'webinar_nurture',
            ],
        ];
    }

    /** @return array<int, string> */
    private function acceptedChannels(
        array $validated,
        MessagePurpose $purpose,
        string $scope,
    ): array {
        $channels = [];

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            if (! ($validated["{$purpose->value}_{$channel->value}_consent"] ?? false)) {
                continue;
            }

            if (! $this->messageChannelAvailability->isVisibleForSurface(
                channel: $channel,
                surface: 'webinar_registrations',
                purpose: $purpose->value,
                scope: $scope,
            )) {
                continue;
            }

            $channels[] = $channel->value;
        }

        return $channels;
    }
}