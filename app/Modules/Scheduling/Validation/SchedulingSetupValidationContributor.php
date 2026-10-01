<?php

namespace App\Modules\Scheduling\Validation;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServicePrerequisite;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\BookingSubjectProviderRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;

final class SchedulingSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'scheduling';

    private const PUBLIC_SOURCE = 'scheduling.public';

    private const STAFF_SOURCE = 'scheduling.staff';

    private const BOOKING_RULES_SOURCE = 'scheduling.booking_rules';

    public function __construct(
        private readonly UserAccessService $access,
        private readonly BookingSubjectProviderRegistry $bookingSubjects,
    ) {}

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    public function findings(): iterable
    {
        yield from $this->publicBookingFindings();
        yield from $this->staffIdentityFindings();
        yield from $this->bookingRuleFindings();
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function publicBookingFindings(): iterable
    {
        $configured = (bool) config('scheduling.public.configured', false);
        $enabled = (bool) config('scheduling.public.enabled', false);

        if (! $configured && ! $enabled) {
            return;
        }

        $url = config('scheduling.public.url');
        $host = config('scheduling.public.host');
        $scheme = config('scheduling.public.scheme');

        $valid = $enabled
            && is_string($url)
            && trim($url) !== ''
            && is_string($host)
            && trim($host) !== ''
            && is_string($scheme)
            && in_array(strtolower(trim($scheme)), ['http', 'https'], true);

        if ($valid) {
            return;
        }

        yield new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: 'scheduling.public_app_url_invalid',
            message: 'Scheduling public booking URL is configured but invalid. SCHEDULING_APP_URL must be a root-level http:// or https:// origin without credentials, path, query, or fragment.',
            source: self::PUBLIC_SOURCE,
            path: 'scheduling.public.url',
            module: self::MODULE,
            context: [
                'configured' => $configured,
                'public_enabled' => $enabled,
            ],
        );
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function staffIdentityFindings(): iterable
    {
        if (! Schema::hasTable('scheduling_hosts') || ! Schema::hasTable('users')) {
            return;
        }

        $userMorphClass = (new User())->getMorphClass();

        foreach (SchedulingHost::query()
            ->where('source', SchedulingHost::SOURCE_MANUAL)
            ->where('status', SchedulingHost::STATUS_ACTIVE)
            ->orderBy('id')
            ->get() as $host
        ) {
            if ($host->hostable_type !== $userMorphClass || ! is_numeric($host->hostable_id)) {
                yield $this->staffIdentityFinding(
                    host: $host,
                    code: 'scheduling.staff_user_identity_missing',
                    message: 'Active Scheduling staff must be linked to an active CRM user. Reconnect this staff record from Scheduling > Staff & Providers.',
                );

                continue;
            }

            $user = User::query()->find((int) $host->hostable_id);

            if (! $user instanceof User || ! $this->access->isActive($user)) {
                yield $this->staffIdentityFinding(
                    host: $host,
                    code: 'scheduling.staff_user_identity_unavailable',
                    message: 'Active Scheduling staff is linked to a missing or inactive CRM user. Update Team & Access or the Scheduling staff record before accepting appointments.',
                );
            }
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function bookingRuleFindings(): iterable
    {
        if (! Schema::hasTable('bookable_services')) {
            return;
        }

        $services = BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        if ($services->isEmpty()) {
            return;
        }

        $knownSubjectKeys = array_keys($this->bookingSubjects->all());

        foreach ($services as $service) {
            $subjectKey = $service->bookingSubjectKey();

            if (! in_array($subjectKey, $knownSubjectKeys, true)) {
                yield new SetupValidationFinding(
                    severity: SetupValidationFinding::SEVERITY_ERROR,
                    code: 'scheduling.booking_subject_provider_missing',
                    message: 'An Appointment Type uses a booking subject that is unavailable. Enable or repair the module that provides that booking subject before accepting appointments.',
                    source: self::BOOKING_RULES_SOURCE,
                    path: 'bookable_services.'.$service->getKey().'.booking_subject_key',
                    module: self::MODULE,
                    context: [
                        'bookable_service_id' => (int) $service->getKey(),
                        'bookable_service_key' => (string) $service->key,
                        'booking_subject_key' => $subjectKey,
                    ],
                );
            }
        }

        if (! Schema::hasTable('bookable_service_prerequisites')) {
            return;
        }

        foreach (BookableServicePrerequisite::query()
            ->where('is_active', true)
            ->with(['service', 'prerequisiteService'])
            ->orderBy('id')
            ->get() as $prerequisite
        ) {
            $service = $prerequisite->service;
            $requiredService = $prerequisite->prerequisiteService;

            if (! $service instanceof BookableService
                || ! $requiredService instanceof BookableService
                || $service->trashed()
                || $service->status !== BookableService::STATUS_ACTIVE
            ) {
                continue;
            }

            if ((int) $service->getKey() === (int) $requiredService->getKey()) {
                yield $this->bookingRuleFinding(
                    prerequisite: $prerequisite,
                    code: 'scheduling.prerequisite_self_reference',
                    message: 'An Appointment Type cannot require itself as a prerequisite.',
                );
            }

            if ($service->bookingSubjectKey() !== $requiredService->bookingSubjectKey()) {
                yield $this->bookingRuleFinding(
                    prerequisite: $prerequisite,
                    code: 'scheduling.prerequisite_subject_mismatch',
                    message: 'A prerequisite must use the same booking subject as the Appointment Type that requires it.',
                    context: [
                        'booking_subject_key' => $service->bookingSubjectKey(),
                        'prerequisite_booking_subject_key' => $requiredService->bookingSubjectKey(),
                    ],
                );
            }

            if ((int) $prerequisite->required_completions < 1) {
                yield $this->bookingRuleFinding(
                    prerequisite: $prerequisite,
                    code: 'scheduling.prerequisite_completion_count_invalid',
                    message: 'A prerequisite must require at least one completed appointment.',
                );
            }

            if ($prerequisite->valid_for_days !== null
                && (int) $prerequisite->valid_for_days < 1
            ) {
                yield $this->bookingRuleFinding(
                    prerequisite: $prerequisite,
                    code: 'scheduling.prerequisite_validity_invalid',
                    message: 'A prerequisite validity window must be at least one day when set.',
                );
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function bookingRuleFinding(
        BookableServicePrerequisite $prerequisite,
        string $code,
        string $message,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::BOOKING_RULES_SOURCE,
            path: 'bookable_service_prerequisites.'.$prerequisite->getKey(),
            module: self::MODULE,
            context: [
                'bookable_service_prerequisite_id' => (int) $prerequisite->getKey(),
                'bookable_service_id' => (int) $prerequisite->bookable_service_id,
                'prerequisite_bookable_service_id' => (int) $prerequisite->prerequisite_bookable_service_id,
                ...$context,
            ],
        );
    }

    private function staffIdentityFinding(
        SchedulingHost $host,
        string $code,
        string $message,
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::STAFF_SOURCE,
            path: 'scheduling_hosts.'.$host->getKey().'.hostable',
            module: self::MODULE,
            context: [
                'scheduling_host_id' => (int) $host->getKey(),
                'scheduling_host_key' => (string) $host->key,
                'scheduling_host_name' => (string) $host->name,
            ],
        );
    }
}