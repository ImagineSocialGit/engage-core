<?php

namespace App\Modules\Core\TokenContracts;

use App\Modules\Core\Services\SiteSettings\SiteSettingResolver;
use App\Support\TokenContracts\Contracts\ComputedTokenValueProvider;
use InvalidArgumentException;

class SiteSettingComputedTokenValueProvider implements ComputedTokenValueProvider
{
    public function __construct(
        private readonly SiteSettingResolver $settings,
    ) {}

    public function value(string $sourcePath, array $context): mixed
    {
        return match ($sourcePath) {
            'site_settings.client_name' => $this->settings->clientName(),
            'site_settings.client_signature' => $this->settings->clientSignature(),
            default => throw new InvalidArgumentException(
                "Unsupported site setting token source path [{$sourcePath}]."
            ),
        };
    }
}
