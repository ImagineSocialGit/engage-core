<?php

namespace App\Modules\Scheduling\Validation;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;

final class SchedulingSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'scheduling';

    private const PUBLIC_SOURCE = 'scheduling.public';

    private const STAFF_SOURCE = 'scheduling.staff';

    public function __construct(
        private readonly UserAccessService $access,
    ) {}

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    public function findings(): iterable
    {
        yield from $this->publicBookingFindings();
        yield from $this->staffIdentityFindings();
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