<?php

namespace App\Support\HumanVerification\Contracts;

/**
 * An enabled module declares a browser-facing surface whose verification is
 * mandatory outside local/testing. It owns its identifier and provider action.
 * Core knows only this interface; individual modules register contributors.
 */
interface RequiredPublicHumanVerificationSurface
{
    public const TAG = 'human_verification.required_public_surfaces';

    public function moduleKey(): string;

    public function surface(): string;

    public function action(): string;
}