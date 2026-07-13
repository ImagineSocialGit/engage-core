<?php

namespace App\Modules\Core\Services\SiteSettings;

use App\Modules\Core\Models\SiteSetting;

class SiteSettingResolver
{
    public function get(string $key, mixed $default = null): mixed
    {
        $value = SiteSetting::query()
            ->where('key', $key)
            ->value('value');

        return $value ?? $default;
    }

    public function clientName(): string
    {
        return (string) $this->get(
            key: 'client_name',
            default: config('client.name', config('app.name')),
        );
    }

    public function clientSignature(): string
    {
        return (string) $this->get(
            key: 'client_signature',
            default: $this->clientName(),
        );
    }
}
