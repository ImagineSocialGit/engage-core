<?php

declare(strict_types=1);

if (! function_exists('cdn_image')) {
    function cdn_image(string $path, ?string $file = null): string
    {
        $base = rtrim((string) config('filesystems.disks.spaces.url', env('CDN_BASE_URL')), '/');
        $path = trim($path, '/');

        if ($file !== null) {
            $file = ltrim($file, '/');

            return "{$base}/images/{$path}/{$file}";
        }

        return "{$base}/images/{$path}";
    }
}

if (! function_exists('module_enabled')) {
    function module_enabled(string $key): bool
    {
        return app(\App\Support\Modules\ModuleManager::class)->enabled($key);
    }
}


if (! function_exists('module_tone')) {
    /**
     * Return muted wayfinding classes for a module.
     *
     * Module tones are orientation cues only. Urgency/severity colors should be
     * applied separately and should visually win over these classes.
     *
     * @return array<string, string>|string
     */
    function module_tone(string $module, ?string $slot = null): array|string
    {
        $module = str_replace('-', '_', strtolower(trim($module)));
        $tone = config("modules.modules.{$module}.ui.tone", 'slate');

        if (! is_string($tone) || trim($tone) === '') {
            $tone = 'slate';
        }

        $tone = str_replace('-', '_', strtolower(trim($tone)));
        $classes = config("modules.tones.{$tone}", config('modules.tones.slate', []));

        if (! is_array($classes)) {
            $classes = [];
        }

        if ($slot === null) {
            return $classes;
        }

        $slot = str_replace('-', '_', strtolower(trim($slot)));
        $value = $classes[$slot] ?? config("modules.tones.slate.{$slot}", '');

        return is_string($value) ? $value : '';
    }
}
