<?php

namespace App\Modules\Scheduling\Validation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

final class SchedulingSetupValidationContributor implements SetupValidationContributor
{
    private const MODULE = 'scheduling';

    private const SOURCE = 'scheduling.public';

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    public function findings(): iterable
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
            source: self::SOURCE,
            path: 'scheduling.public.url',
            module: self::MODULE,
            context: [
                'configured' => $configured,
                'public_enabled' => $enabled,
            ],
        );
    }
}