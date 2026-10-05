<?php

namespace App\Modules\Portal\Services;

final class PortalPresentationResolver
{
    /** @return array<string, mixed> */
    public function resolve(string $title): array
    {
        $primary = $this->hexColor(config('portal.presentation.primary_color'), config('public_surfaces.theme.colors.primary'), '#0f766e');
        $accent = $this->hexColor(config('portal.presentation.accent_color'), config('public_surfaces.theme.colors.accent'), '#14b8a6');

        return [
            'title' => trim($title) !== '' ? trim($title) : 'Portal',
            'brand_name' => $this->string(config('portal.presentation.brand_name'), config('client.name'), config('app.name'), 'Portal'),
            'surface_label' => $this->string(config('portal.presentation.surface_label'), null, null, 'Portal'),
            'primary_color' => $primary,
            'accent_color' => $accent,
            'surface_color' => $this->hexColor(config('portal.presentation.surface_color'), config('public_surfaces.theme.colors.surface'), '#ffffff'),
            'background_color' => $this->hexColor(config('portal.presentation.background_color'), config('public_surfaces.theme.colors.background'), '#f8fafc'),
            'logo' => config('portal.presentation.logo') ?? config('public_surfaces.theme.brand.logo'),
            'logo_url' => $this->publicUrl(config('portal.presentation.logo_url')),
            'robots' => $this->string(config('portal.presentation.robots'), null, null, 'noindex,nofollow'),
        ];
    }

    private function string(mixed $preferred, mixed $fallback, mixed $secondFallback, string $default): string
    {
        foreach ([$preferred, $fallback, $secondFallback, $default] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 1000);
            }
        }

        return $default;
    }

    private function hexColor(mixed $preferred, mixed $fallback, string $default): string
    {
        foreach ([$preferred, $fallback, $default] as $value) {
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', trim($value)) === 1) {
                return strtolower(trim($value));
            }
        }

        return $default;
    }

    private function publicUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}