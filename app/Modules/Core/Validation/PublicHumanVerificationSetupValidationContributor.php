<?php

namespace App\Modules\Core\Validation;

use App\Support\HumanVerification\HumanVerificationManager;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Throwable;

final class PublicHumanVerificationSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'human_verification';
    private const MODULE = 'core';

    public function __construct(
        private readonly HumanVerificationManager $verification,
    ) {}

    public function findings(): iterable
    {
        if (! (bool) config('human_verification.enabled', false)) {
            return;
        }

        try {
            $this->verification->validateConfiguration();
        } catch (Throwable $exception) {
            yield new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_ERROR,
                code: 'core.public_human_verification.invalid',
                message: $exception->getMessage(),
                source: self::SOURCE,
                path: self::SOURCE,
                module: self::MODULE,
            );
        }
    }
}