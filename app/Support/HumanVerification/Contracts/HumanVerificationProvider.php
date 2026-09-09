<?php

namespace App\Support\HumanVerification\Contracts;

use App\Support\HumanVerification\Data\HumanVerificationRequest;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Data\HumanVerificationWidget;

interface HumanVerificationProvider
{
    public function key(): string;

    /**
     * @param array<string, mixed> $configuration
     */
    public function validateConfiguration(array $configuration): void;

    /**
     * @param array<string, mixed> $configuration
     */
    public function widget(
        string $action,
        array $configuration,
    ): HumanVerificationWidget;

    /**
     * @param array<string, mixed> $configuration
     */
    public function verify(
        HumanVerificationRequest $request,
        array $configuration,
    ): HumanVerificationResult;
}