<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Actions\CompletePublicBookingAction;
use App\Modules\Scheduling\Actions\CreatePublicBookingHoldAction;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Actions\IssuePublicBookingSlotOfferAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Data\SchedulingLocationSnapshot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Requests\CompletePublicBookingRequest;
use App\Modules\Scheduling\Requests\CreatePublicBookingHoldRequest;
use App\Modules\Scheduling\Requests\IssuePublicBookingDestinationVerificationRequest;
use App\Modules\Scheduling\Requests\IssuePublicBookingSlotOfferRequest;
use App\Modules\Scheduling\Requests\VerifyPublicBookingDestinationVerificationRequest;
use App\Modules\Scheduling\Requests\PreparePublicBookingRequest;
use App\Modules\Scheduling\Requests\ResendPublicBookingDestinationVerificationRequest;
use App\Modules\Scheduling\Services\PublicBookingDestinationVerificationService;
use App\Modules\Scheduling\Services\SchedulingLocationSnapshotResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class PublicBookingController extends Controller
{
    public function index(): View
    {
        return view('scheduling.public.index', $this->pageData());
    }

    public function show(
        Request $request,
        string $serviceKey,
        FindBookableAvailabilityAction $findAvailability,
        SchedulingLocationSnapshotResolver $locationSnapshots,
    ): View {
        $service = $this->publicService($serviceKey);
        $displayTimezone = $this->serviceTimezone($service);
        $today = CarbonImmutable::now($displayTimezone)->startOfDay();
        $maximumDate = $this->maximumPublicDate($service, $today);
        $location = $this->availabilityLocation(
            request: $request,
            service: $service,
            locationSnapshots: $locationSnapshots,
        );
        $requiresCustomerSitePreparation = $service->location_type
            === BookableService::LOCATION_TYPE_CUSTOMER_SITE
            && ! $location instanceof SchedulingLocationSnapshot;

        if ($requiresCustomerSitePreparation) {
            return view('scheduling.public.index', $this->pageData([
                'selectedService' => $service,
                'displayTimezone' => $displayTimezone,
                'maximumDate' => $maximumDate,
                'requiresCustomerSitePreparation' => true,
            ]));
        }

        if ($service->usesRangeDuration()) {
            return view('scheduling.public.index', $this->pageData([
                'selectedService' => $service,
                'displayTimezone' => $displayTimezone,
                'maximumDate' => $maximumDate,
                'preparedLocation' => $this->locationPresentation($location),
            ]));
        }

        $selectedDate = $this->selectedDate(
            value: $request->query('date'),
            timezone: $displayTimezone,
            minimum: $today,
            maximum: $maximumDate,
        );

        $slots = $findAvailability->handle(new AvailabilitySearch(
            service: $service,
            startsAt: $selectedDate->utc(),
            endsAt: $selectedDate->addDay()->utc(),
            displayTimezone: $displayTimezone,
            evaluatedAt: CarbonImmutable::now('UTC'),
            location: $location,
        ));

        return view('scheduling.public.index', $this->pageData([
            'selectedService' => $service,
            'selectedDate' => $selectedDate,
            'displayTimezone' => $displayTimezone,
            'availableTimes' => $this->publicTimes($slots, $displayTimezone),
            'maximumDate' => $maximumDate,
            'preparedLocation' => $this->locationPresentation($location),
        ]));
    }

    public function prepare(
        PreparePublicBookingRequest $request,
        string $serviceKey,
        SchedulingLocationSnapshotResolver $locationSnapshots,
    ): RedirectResponse {
        $service = $this->publicService($serviceKey);

        abort_unless(
            $service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            404,
        );

        try {
            $location = $locationSnapshots->normalizeAddress(
                type: BookableService::LOCATION_TYPE_CUSTOMER_SITE,
                input: $request->customerSiteAddress(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'address_line_1' => $exception->getMessage(),
            ]);
        }

        $request->session()->put(
            $this->customerSiteSessionKey($service),
            $location->toColumns(),
        );

        return redirect()->route(
            'scheduling.public.services.show',
            ['serviceKey' => $service->key],
        );
    }

    public function offer(
        IssuePublicBookingSlotOfferRequest $request,
        string $serviceKey,
        IssuePublicBookingSlotOfferAction $issuePublicOffer,
        SchedulingLocationSnapshotResolver $locationSnapshots,
    ): RedirectResponse {
        $service = $this->publicService($serviceKey);
        $location = $service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE
            ? $this->availabilityLocation(
                request: $request,
                service: $service,
                locationSnapshots: $locationSnapshots,
            )
            : null;

        if ($service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE
            && ! $location instanceof SchedulingLocationSnapshot
        ) {
            throw ValidationException::withMessages([
                'address_line_1' => 'Provide the service address before choosing an appointment time.',
            ]);
        }

        try {
            $startsAt = $request->startsAt();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $service->usesRangeDuration() ? 'range_starts_at' : 'starts_at' => $exception->getMessage(),
            ]);
        }

        try {
            $endsAt = $request->endsAt();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'range_ends_at' => $exception->getMessage(),
            ]);
        }

        try {
            $offer = $issuePublicOffer->handle(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                location: $location,
            );
        } catch (DomainException) {
            throw ValidationException::withMessages([
                ($service->usesRangeDuration() ? 'range_ends_at' : 'starts_at') => $service->usesRangeDuration()
                    ? 'That appointment range could not be reserved. Check the dates and try again.'
                    : 'That appointment time could not be reserved. Choose an available time and try again.',
            ]);
        }

        return redirect()->route(
            'scheduling.public.offers.show',
            ['offerId' => $offer->offer_id],
        );
    }

    public function reviewOffer(
        Request $request,
        string $offerId,
        PublicBookingDestinationVerificationService $destinationVerification,
    ): View|RedirectResponse {
        $offer = $this->publicOffer($offerId);

        if ($offer->bookingHold instanceof BookingHold) {
            $this->forgetDestinationVerificationState($request, $offer);

            return redirect()->route(
                'scheduling.public.holds.show',
                ['holdId' => $offer->bookingHold->hold_id],
            );
        }

        $service = $offer->bookableService;

        abort_unless($service instanceof BookableService, 404);

        return view('scheduling.public.index', $this->pageData([
            'offerSummary' => $this->offerSummary($offer, $service),
            'destinationVerification' => $this->destinationVerificationSummary(
                request: $request,
                offer: $offer,
                verification: $destinationVerification,
            ),
        ]));
    }

    public function issueDestinationVerification(
        IssuePublicBookingDestinationVerificationRequest $request,
        string $offerId,
        PublicBookingDestinationVerificationService $destinationVerification,
    ): RedirectResponse {
        $offer = $this->publicOffer($offerId);

        try {
            $challenge = $destinationVerification->issue(
                offer: $offer,
                sessionId: $this->bookingSessionId($request),
                channel: $request->channel(),
                destination: $request->destination(),
                sourceIp: $request->ip(),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'destination' => 'Enter a valid email address or mobile phone number and try again.',
            ]);
        }

        $request->session()->put(
            $this->destinationVerificationSessionKey($offer),
            [
                'challenge_id' => $challenge->challengeId,
                'channel' => $challenge->channel,
                'destination' => $challenge->destination,
                'masked_destination' => $challenge->maskedDestination,
                'challenge_expires_at' => $challenge->expiresAt->toISOString(),
                'resend_available_at' => $challenge->resendAvailableAt->toISOString(),
            ],
        );

        return redirect()->route(
            'scheduling.public.offers.show',
            ['offerId' => $offer->offer_id],
        );
    }

    public function verifyDestination(
        VerifyPublicBookingDestinationVerificationRequest $request,
        string $offerId,
        PublicBookingDestinationVerificationService $destinationVerification,
        CreatePublicBookingHoldAction $createPublicBookingHold,
    ): RedirectResponse {
        $offer = $this->publicOffer($offerId);
        $state = $this->storedDestinationVerificationState($request, $offer);
        $challengeId = is_string($state['challenge_id'] ?? null)
            ? trim($state['challenge_id'])
            : '';

        if ($challengeId === '') {
            throw ValidationException::withMessages([
                'code' => 'Request a new verification code before continuing.',
            ]);
        }

        try {
            $proof = $destinationVerification->verify(
                offer: $offer,
                sessionId: $this->bookingSessionId($request),
                challengeId: $challengeId,
                code: $request->code(),
            );

            $hold = $createPublicBookingHold->handle(
                offerId: $offer->offer_id,
                idempotencyKey: (string) Str::uuid(),
                sessionId: $this->bookingSessionId($request),
                verificationProofToken: $proof->token,
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'code' => 'The code is incorrect, expired, or this time is no longer available. Choose another time if needed and try again.',
            ]);
        }

        $this->storeHoldContactPrefill(
            request: $request,
            hold: $hold,
            verifiedChannel: $proof->channel,
            verifiedDestination: $proof->destination,
        );
        $request->session()->flash(
            'scheduling.public.verification_completed_channel',
            $proof->channel,
        );
        $this->forgetDestinationVerificationState($request, $offer);

        return redirect()->route(
            'scheduling.public.holds.show',
            ['holdId' => $hold->hold_id],
        );
    }

    public function resendDestinationVerification(
        ResendPublicBookingDestinationVerificationRequest $request,
        string $offerId,
        PublicBookingDestinationVerificationService $destinationVerification,
    ): RedirectResponse {
        $offer = $this->publicOffer($offerId);
        $state = $this->storedDestinationVerificationState($request, $offer);
        $challengeId = is_string($state['challenge_id'] ?? null)
            ? trim($state['challenge_id'])
            : '';

        if ($challengeId === '') {
            throw ValidationException::withMessages([
                'verification' => 'Request a new verification code before trying to resend it.',
            ]);
        }

        try {
            $challenge = $destinationVerification->resend(
                offer: $offer,
                sessionId: $this->bookingSessionId($request),
                challengeId: $challengeId,
                sourceIp: $request->ip(),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'verification' => 'We could not send another code yet. Wait a moment and try again.',
            ]);
        }

        $request->session()->put(
            $this->destinationVerificationSessionKey($offer),
            [
                'challenge_id' => $challenge->challengeId,
                'channel' => $challenge->channel,
                'destination' => $challenge->destination,
                'masked_destination' => $challenge->maskedDestination,
                'challenge_expires_at' => $challenge->expiresAt->toISOString(),
                'resend_available_at' => $challenge->resendAvailableAt->toISOString(),
            ],
        );

        return redirect()->route(
            'scheduling.public.offers.show',
            ['offerId' => $offer->offer_id],
        );
    }

    public function hold(
        CreatePublicBookingHoldRequest $request,
        string $offerId,
        CreatePublicBookingHoldAction $createPublicBookingHold,
    ): RedirectResponse {
        $offer = $this->publicOffer($offerId);
        $state = $this->storedDestinationVerificationState($request, $offer);
        $proofToken = is_string($state['proof_token'] ?? null)
            ? trim($state['proof_token'])
            : null;

        try {
            $hold = $createPublicBookingHold->handle(
                offerId: $offer->offer_id,
                idempotencyKey: $request->idempotencyKey(),
                sessionId: $this->bookingSessionId($request),
                verificationProofToken: $proofToken,
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'booking' => 'We could not reserve this time. Confirm your code again or choose another time.',
            ]);
        }

        $this->storeHoldContactPrefill(
            request: $request,
            hold: $hold,
            verifiedChannel: is_string($state['verified_channel'] ?? null)
                ? trim($state['verified_channel'])
                : null,
            verifiedDestination: is_string($state['destination'] ?? null)
                ? trim($state['destination'])
                : null,
        );

        $this->forgetDestinationVerificationState($request, $offer);

        return redirect()->route(
            'scheduling.public.holds.show',
            ['holdId' => $hold->hold_id],
        );
    }

    public function review(Request $request, string $holdId): View
    {
        $hold = $this->publicHold($holdId);
        $service = BookableService::withTrashed()
            ->whereKey($hold->bookable_service_id)
            ->first();

        abort_unless($service instanceof BookableService, 404);

        return view('scheduling.public.index', $this->pageData([
            'holdSummary' => $this->holdSummary($hold, $service),
            'contactPrefill' => $this->holdContactPrefill($request, $hold),
            'verificationCompletedChannel' => $request->session()->pull(
                'scheduling.public.verification_completed_channel',
            ),
        ]));
    }

    public function complete(
        CompletePublicBookingRequest $request,
        string $holdId,
        CompletePublicBookingAction $completePublicBooking,
    ): RedirectResponse {
        $hold = $this->publicHold($holdId);
        $contactPrefill = $this->holdContactPrefill($request, $hold);

        if ($contactPrefill['verified_channel'] === 'email'
            && (! is_string($contactPrefill['email'])
                || ! hash_equals(
                    strtolower(trim($contactPrefill['email'])),
                    $request->attendeeEmail(),
                ))
        ) {
            throw ValidationException::withMessages([
                'email' => 'Use the email address that was confirmed for this booking.',
            ]);
        }

        if ($contactPrefill['verified_channel'] === 'sms'
            && (! is_string($contactPrefill['phone'])
                || ! is_string($request->attendeePhone())
                || ! hash_equals(
                    preg_replace('/\D+/', '', $contactPrefill['phone']) ?? '',
                    preg_replace('/\D+/', '', $request->attendeePhone()) ?? '',
                ))
        ) {
            throw ValidationException::withMessages([
                'phone' => 'Use the mobile phone number that was confirmed for this booking.',
            ]);
        }

        try {
            $completePublicBooking->handle(
                holdId: $hold->hold_id,
                firstName: $request->attendeeFirstName(),
                lastName: $request->attendeeLastName(),
                email: $request->attendeeEmail(),
                phone: $request->attendeePhone(),
                publicSubmissionAttemptId: $request->publicSubmissionAttemptId(),
                disclosure: $this->publicBookingDisclosure(),
            );
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation can no longer be completed. Choose another appointment time.',
            ]);
        }

        $request->session()->forget($this->holdContactPrefillSessionKey($hold));

        return redirect()->route(
            'scheduling.public.holds.show',
            ['holdId' => $hold->hold_id],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function pageData(array $overrides = []): array
    {
        return array_replace([
            'services' => $this->publicServices(),
            'selectedService' => null,
            'selectedDate' => null,
            'displayTimezone' => null,
            'availableTimes' => [],
            'maximumDate' => null,
            'preparedLocation' => null,
            'publicPresentation' => $this->publicPresentation(),
            'requiresCustomerSitePreparation' => false,
            'offerSummary' => null,
            'destinationVerification' => [
                'required' => false,
                'available_channels' => [],
                'verified' => false,
                'verified_channel' => null,
                'masked_destination' => null,
                'challenge_active' => false,
                'challenge_expires_at' => null,
                'resend_available_at' => null,
            ],
            'holdSummary' => null,
            'contactPrefill' => [
                'verified_channel' => null,
                'email' => null,
                'phone' => null,
            ],
            'verificationCompletedChannel' => null,
        ], $overrides);
    }

    /**
     * @return Collection<int, BookableService>
     */
    private function publicServices(): Collection
    {
        return BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(
                fn (BookableService $service): bool => $service->hasCompleteAppointmentFormat(),
            )
            ->values();
    }

    private function publicService(string $serviceKey): BookableService
    {
        $serviceKey = trim($serviceKey);

        if ($serviceKey === '') {
            abort(404);
        }

        $service = BookableService::query()
            ->where('key', $serviceKey)
            ->where('status', BookableService::STATUS_ACTIVE)
            ->where('is_public', true)
            ->first();

        abort_unless(
            $service instanceof BookableService
                && $service->hasCompleteAppointmentFormat(),
            404,
        );

        return $service;
    }

    private function publicOffer(string $offerId): BookableSlotOffer
    {
        $offerId = trim($offerId);

        if ($offerId === '') {
            abort(404);
        }

        $offer = BookableSlotOffer::query()
            ->with(['bookableService', 'bookingHold'])
            ->where('offer_id', $offerId)
            ->whereNull('reschedule_appointment_id')
            ->first();

        abort_unless($offer instanceof BookableSlotOffer, 404);
        abort_unless(
            $offer->bookableService instanceof BookableService
                && $offer->bookableService->is_public
                && $offer->bookableService->hasCompleteAppointmentFormat(),
            404,
        );

        return $offer;
    }

    private function publicHold(string $holdId): BookingHold
    {
        $holdId = trim($holdId);

        if ($holdId === '') {
            abort(404);
        }

        $hold = BookingHold::query()
            ->with('appointment')
            ->where('hold_id', $holdId)
            ->first();

        abort_unless($hold instanceof BookingHold, 404);

        return $hold;
    }

    private function availabilityLocation(
        Request $request,
        BookableService $service,
        SchedulingLocationSnapshotResolver $locationSnapshots,
    ): ?SchedulingLocationSnapshot {
        if ($service->location_type === BookableService::LOCATION_TYPE_CUSTOMER_SITE) {
            return $this->storedCustomerSiteLocation($request, $service);
        }

        try {
            return $locationSnapshots->forCommitment($service);
        } catch (DomainException) {
            return null;
        }
    }

    private function storedCustomerSiteLocation(
        Request $request,
        BookableService $service,
    ): ?SchedulingLocationSnapshot {
        $key = $this->customerSiteSessionKey($service);
        $stored = $request->session()->get($key);

        if (! is_array($stored)) {
            return null;
        }

        try {
            $snapshot = SchedulingLocationSnapshot::fromPersisted(
                type: is_string($stored['location_type'] ?? null)
                    ? $stored['location_type']
                    : null,
                details: $stored['location_details'] ?? null,
            );
        } catch (InvalidArgumentException) {
            $request->session()->forget($key);

            return null;
        }

        if (! $snapshot instanceof SchedulingLocationSnapshot
            || ! $snapshot->canonical
            || ! $snapshot->isCustomerSite()
        ) {
            $request->session()->forget($key);

            return null;
        }

        return $snapshot;
    }

    private function customerSiteSessionKey(BookableService $service): string
    {
        return 'scheduling.public.customer_site_location.'.(int) $service->getKey();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationPresentation(?SchedulingLocationSnapshot $location): ?array
    {
        if (! $location instanceof SchedulingLocationSnapshot) {
            return null;
        }

        $details = is_array($location->details) ? $location->details : [];
        $address = is_array($details['address'] ?? null)
            ? $details['address']
            : [];

        return [
            'type' => $location->type,
            'label' => is_string($details['label'] ?? null)
                ? $details['label']
                : null,
            'instructions' => is_string($details['instructions'] ?? null)
                ? $details['instructions']
                : null,
            'url' => is_string($details['url'] ?? null)
                ? $details['url']
                : null,
            'formatted_address' => is_string($address['formatted_address'] ?? null)
                ? $address['formatted_address']
                : null,
            'address_line_1' => $address['address_line_1'] ?? null,
            'address_line_2' => $address['address_line_2'] ?? null,
            'city' => $address['city'] ?? null,
            'region' => $address['region'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? 'US',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationVerificationSummary(
        Request $request,
        BookableSlotOffer $offer,
        PublicBookingDestinationVerificationService $verification,
    ): array {
        $channels = $verification->availableChannels();
        $state = $this->storedDestinationVerificationState($request, $offer);
        $proofToken = is_string($state['proof_token'] ?? null)
            ? trim($state['proof_token'])
            : '';
        $verified = $proofToken !== ''
            && $verification->hasValidProof(
                offer: $offer,
                sessionId: $this->bookingSessionId($request),
                proofToken: $proofToken,
            );

        if (! $verified && $proofToken !== '') {
            $state = [];
            $request->session()->forget(
                $this->destinationVerificationSessionKey($offer),
            );
        }

        $challengeActive = false;
        $challengeExpiresAt = null;
        $resendAvailableAt = null;

        if (! $verified && is_string($state['challenge_id'] ?? null)) {
            try {
                $challengeExpiresAt = CarbonImmutable::parse(
                    (string) ($state['challenge_expires_at'] ?? ''),
                    'UTC',
                )->utc();
                $resendAvailableAt = CarbonImmutable::parse(
                    (string) ($state['resend_available_at'] ?? ''),
                    'UTC',
                )->utc();
                $challengeActive = $challengeExpiresAt->isFuture()
                    && $offer->isActiveAt();
            } catch (Throwable) {
                $challengeActive = false;
            }

            if (! $challengeActive) {
                $state = [];
                $request->session()->forget(
                    $this->destinationVerificationSessionKey($offer),
                );
            }
        }

        return [
            'required' => $channels !== [],
            'available_channels' => $channels,
            'verified' => $verified,
            'verified_channel' => $verified
                && is_string($state['verified_channel'] ?? null)
                    ? $state['verified_channel']
                    : null,
            'masked_destination' => is_string($state['masked_destination'] ?? null)
                ? $state['masked_destination']
                : null,
            'challenge_active' => $challengeActive,
            'challenge_expires_at' => $challengeActive
                ? $challengeExpiresAt?->toISOString()
                : null,
            'resend_available_at' => $challengeActive
                ? $resendAvailableAt?->toISOString()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedDestinationVerificationState(
        Request $request,
        BookableSlotOffer $offer,
    ): array {
        $state = $request->session()->get(
            $this->destinationVerificationSessionKey($offer),
        );

        return is_array($state) ? $state : [];
    }

    private function forgetDestinationVerificationState(
        Request $request,
        BookableSlotOffer $offer,
    ): void {
        $request->session()->forget(
            $this->destinationVerificationSessionKey($offer),
        );
    }

    private function destinationVerificationSessionKey(
        BookableSlotOffer $offer,
    ): string {
        return 'scheduling.public.destination_verification.'.(string) $offer->offer_id;
    }

    private function bookingSessionId(Request $request): string
    {
        $key = 'scheduling.public.booking_session_id';
        $sessionId = $request->session()->get($key);

        if (is_string($sessionId) && trim($sessionId) !== '') {
            return trim($sessionId);
        }

        $sessionId = (string) Str::uuid();

        $request->session()->put($key, $sessionId);

        return $sessionId;
    }

    private function serviceTimezone(BookableService $service): string
    {
        $timezone = trim((string) $service->timezone);

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    private function publicTimezoneLabel(string $timezone): string
    {
        return match ($timezone) {
            'UTC', 'Etc/UTC' => 'UTC',
            'America/New_York',
            'America/Detroit',
            'America/Indiana/Indianapolis',
            'America/Kentucky/Louisville' => 'Eastern Time',
            'America/Chicago',
            'America/Indiana/Knox',
            'America/Menominee',
            'America/North_Dakota/Center' => 'Central Time',
            'America/Denver',
            'America/Boise' => 'Mountain Time',
            'America/Phoenix' => 'Arizona Time',
            'America/Los_Angeles' => 'Pacific Time',
            'America/Anchorage' => 'Alaska Time',
            'Pacific/Honolulu' => 'Hawaii Time',
            default => str_replace('_', ' ', $timezone),
        };
    }

    private function publicAppointmentMethodLabel(?string $locationType): ?string
    {
        return match ($locationType) {
            BookableService::LOCATION_TYPE_PHONE => 'Phone call',
            BookableService::LOCATION_TYPE_VIRTUAL => 'Virtual meeting',
            default => null,
        };
    }

    private function maximumPublicDate(
        BookableService $service,
        CarbonImmutable $today,
    ): CarbonImmutable {
        $configuredDays = max(
            1,
            (int) config('scheduling.public.availability_max_days', 31),
        );
        $serviceDays = max(1, (int) $service->booking_horizon_days);

        return $today->addDays(min($configuredDays, $serviceDays) - 1);
    }

    private function selectedDate(
        mixed $value,
        string $timezone,
        CarbonImmutable $minimum,
        CarbonImmutable $maximum,
    ): CarbonImmutable {
        if ($value === null || $value === '') {
            return $minimum;
        }

        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
        ) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid appointment date.',
            ]);
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value,
                $timezone,
            );
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw ValidationException::withMessages([
                'date' => 'Choose a valid appointment date.',
            ]);
        }

        if ($date->lessThan($minimum) || $date->greaterThan($maximum)) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Choose a date from %s through %s.',
                    $minimum->format('M j, Y'),
                    $maximum->format('M j, Y'),
                ),
            ]);
        }

        return $date;
    }

    /**
     * @param array<int, BookableSlot> $slots
     * @return array<int, array{starts_at: string, start_label: string, end_label: string, full_label: string, period: string}>
     */
    private function publicTimes(array $slots, string $displayTimezone): array
    {
        $times = [];

        foreach ($slots as $slot) {
            $startsAt = $slot->startsAt->setTimezone($displayTimezone);
            $endsAt = $slot->endsAt->setTimezone($displayTimezone);
            $key = $slot->startsAt->toISOString().'|'.$slot->endsAt->toISOString();

            if (array_key_exists($key, $times)) {
                continue;
            }

            $times[$key] = [
                'starts_at' => $slot->startsAt->toISOString(),
                'start_label' => $startsAt->format('g:i A'),
                'end_label' => $endsAt->format('g:i A'),
                'full_label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
                'period' => match (true) {
                    (int) $startsAt->format('G') < 12 => 'morning',
                    (int) $startsAt->format('G') < 17 => 'afternoon',
                    default => 'evening',
                },
            ];
        }

        return array_values($times);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSummary(
        BookableSlotOffer $offer,
        BookableService $service,
    ): array {
        $now = CarbonImmutable::now('UTC');
        $timezone = $this->serviceTimezone($service);
        $startsAt = CarbonImmutable::instance($offer->starts_at)->setTimezone($timezone);
        $endsAt = CarbonImmutable::instance($offer->ends_at)->setTimezone($timezone);
        $active = $offer->isActiveAt($now);
        $locationDetails = is_array($offer->location_details)
            ? $offer->location_details
            : [];

        return [
            'offer_id' => $offer->offer_id,
            'status' => $active ? 'active' : 'expired',
            'remaining_seconds' => $offer->remainingSeconds($now),
            'expires_at' => $offer->expires_at?->toISOString(),
            'service_key' => $service->key,
            'service_name' => $service->name,
            'is_range' => $service->usesRangeDuration(),
            'date' => $startsAt->format('Y-m-d'),
            'date_label' => $startsAt->format('l, F j, Y'),
            'time_label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
            'interval_label' => $startsAt->format('D, M j, Y \a\t g:i A')
                .' – '
                .$endsAt->format('D, M j, Y \a\t g:i A'),
            'timezone' => $timezone,
            'timezone_label' => $this->publicTimezoneLabel($timezone),
            'appointment_method_label' => $this->publicAppointmentMethodLabel($offer->location_type),
            'location_type' => $offer->location_type,
            'location_label' => is_string($locationDetails['label'] ?? null)
                ? $locationDetails['label']
                : null,
            'location_instructions' => is_string($locationDetails['instructions'] ?? null)
                ? $locationDetails['instructions']
                : null,
            'location_address' => is_string(data_get($locationDetails, 'address.formatted_address'))
                ? data_get($locationDetails, 'address.formatted_address')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holdSummary(
        BookingHold $hold,
        BookableService $service,
    ): array {
        $now = CarbonImmutable::now('UTC');
        $timezone = $this->serviceTimezone($service);
        $startsAt = CarbonImmutable::instance($hold->starts_at)->setTimezone($timezone);
        $endsAt = CarbonImmutable::instance($hold->ends_at)->setTimezone($timezone);
        $status = $hold->status;

        if ($status === BookingHold::STATUS_ACTIVE
            && ! $hold->isEffectivelyActive($now)
        ) {
            $status = BookingHold::STATUS_EXPIRED;
        }

        $appointment = $hold->appointment;
        $appointmentStatus = $appointment instanceof Appointment
            ? (string) $appointment->status
            : null;

        $locationDetails = is_array($hold->location_details)
            ? $hold->location_details
            : [];

        return [
            'hold_id' => $hold->hold_id,
            'status' => $status,
            'remaining_seconds' => $hold->remainingSeconds($now),
            'expires_at' => $hold->expires_at?->toISOString(),
            'service_key' => $service->key,
            'service_name' => $service->name,
            'is_range' => $service->usesRangeDuration(),
            'date' => $startsAt->format('Y-m-d'),
            'date_label' => $startsAt->format('l, F j, Y'),
            'time_label' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
            'interval_label' => $startsAt->format('D, M j, Y \a\t g:i A')
                .' – '
                .$endsAt->format('D, M j, Y \a\t g:i A'),
            'timezone' => $timezone,
            'timezone_label' => $this->publicTimezoneLabel($timezone),
            'appointment_method_label' => $this->publicAppointmentMethodLabel($hold->location_type),
            'location_type' => $hold->location_type,
            'location_label' => is_string($locationDetails['label'] ?? null)
                ? $locationDetails['label']
                : null,
            'location_instructions' => is_string($locationDetails['instructions'] ?? null)
                ? $locationDetails['instructions']
                : null,
            'location_address' => is_string(data_get($locationDetails, 'address.formatted_address'))
                ? data_get($locationDetails, 'address.formatted_address')
                : null,
            'appointment_status' => $appointmentStatus,
            'confirmation_pending' => $appointmentStatus === Appointment::STATUS_PENDING,
            'public_submission_attempt_id' => $appointment instanceof Appointment
                && is_string(data_get($appointment->meta, 'reporting.public_submission_attempt_id'))
                    ? data_get($appointment->meta, 'reporting.public_submission_attempt_id')
                    : null,
        ];
    }

    private function storeHoldContactPrefill(
        Request $request,
        BookingHold $hold,
        ?string $verifiedChannel,
        ?string $verifiedDestination,
    ): void {
        if (! in_array($verifiedChannel, ['email', 'sms'], true)
            || $verifiedDestination === null
            || trim($verifiedDestination) === ''
        ) {
            return;
        }

        $verifiedDestination = trim($verifiedDestination);

        $request->session()->put(
            $this->holdContactPrefillSessionKey($hold),
            [
                'verified_channel' => $verifiedChannel,
                'email' => $verifiedChannel === 'email'
                    ? $verifiedDestination
                    : null,
                'phone' => $verifiedChannel === 'sms'
                    ? $verifiedDestination
                    : null,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function holdContactPrefill(Request $request, BookingHold $hold): array
    {
        $stored = $request->session()->get($this->holdContactPrefillSessionKey($hold));

        if (! is_array($stored)) {
            return [
                'verified_channel' => null,
                'email' => null,
                'phone' => null,
            ];
        }

        return [
            'verified_channel' => in_array($stored['verified_channel'] ?? null, ['email', 'sms'], true)
                ? $stored['verified_channel']
                : null,
            'email' => is_string($stored['email'] ?? null)
                ? $stored['email']
                : null,
            'phone' => is_string($stored['phone'] ?? null)
                ? $stored['phone']
                : null,
        ];
    }

    private function holdContactPrefillSessionKey(BookingHold $hold): string
    {
        return 'scheduling.public.hold_contact_prefill.'.(string) $hold->hold_id;
    }

    /** @return array<string, mixed> */
    private function publicPresentation(): array
    {
        $primary = $this->hexColor(
            config('scheduling.public.presentation.primary_color'),
            config('theme.colors.primary'),
            '#0f766e',
        );
        $accent = $this->hexColor(
            config('scheduling.public.presentation.accent_color'),
            config('theme.colors.accent'),
            $primary,
        );

        return [
            'brand_name' => $this->presentationString(
                config('scheduling.public.presentation.brand_name'),
                config('client.name'),
                'Appointments',
            ),
            'heading' => $this->presentationString(
                config('scheduling.public.presentation.heading'),
                null,
                'Schedule an appointment',
            ),
            'intro' => $this->presentationString(
                config('scheduling.public.presentation.intro'),
                null,
                'Choose a service and a time that works for you.',
            ),
            'primary_color' => $primary,
            'accent_color' => $accent,
            'surface_color' => $this->hexColor(
                config('scheduling.public.presentation.surface_color'),
                null,
                '#ffffff',
            ),
            'background_color' => $this->hexColor(
                config('scheduling.public.presentation.background_color'),
                null,
                '#f6f7f8',
            ),
            'logo_url' => $this->safePublicUrl(
                config('scheduling.public.presentation.logo_url'),
            ),
            'page_revision' => $this->presentationString(
                config('scheduling.public.presentation.page_revision'),
                null,
                'scheduling-public-v3',
            ),
            'reporting_enabled' => (bool) config(
                'scheduling.public.reporting_enabled',
                true,
            ),
            'consent_text' => $this->publicBookingDisclosureText(),
        ];
    }

    /** @return array<string, string> */
    private function publicBookingDisclosure(): array
    {
        $text = $this->publicBookingDisclosureText();

        return [
            'key' => 'scheduling.public_booking.communications',
            'version' => $this->presentationString(
                config('scheduling.public.presentation.disclosure_version'),
                null,
                '1',
            ),
            'text_hash' => hash('sha256', $text),
        ];
    }

    private function publicBookingDisclosureText(): string
    {
        $client = $this->presentationString(
            config('scheduling.public.presentation.brand_name'),
            config('client.name'),
            'the business',
        );

        return $this->presentationString(
            config('scheduling.public.presentation.consent_text'),
            null,
            "By completing this booking, you agree that {$client} may contact you about this appointment through the contact information you provide. Message and data rates may apply. Reply STOP to opt out of texts.",
        );
    }

    private function presentationString(
        mixed $preferred,
        mixed $fallback,
        string $default,
    ): string {
        foreach ([$preferred, $fallback, $default] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 1000);
            }
        }

        return $default;
    }

    private function hexColor(mixed $preferred, mixed $fallback, string $default): string
    {
        foreach ([$preferred, $fallback, $default] as $value) {
            if (is_string($value)
                && preg_match('/^#[0-9a-fA-F]{6}$/D', trim($value)) === 1
            ) {
                return strtolower(trim($value));
            }
        }

        return $default;
    }

    private function safePublicUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
        ) {
            return mb_substr($value, 0, 2048);
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && trim($parts['host']) !== ''
                ? mb_substr($value, 0, 2048)
                : null;
    }
}