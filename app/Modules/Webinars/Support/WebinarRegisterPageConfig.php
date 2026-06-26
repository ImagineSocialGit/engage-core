<?php

namespace App\Modules\Webinars\Support;

class WebinarRegisterPageConfig
{
    public function content(string $page, string $seriesSlug, array $seriesMeta = []): array
    {
        return array_replace_recursive(
            config('webinars.content', []),
            config("webinars.{$page}.content", []),
            $seriesMeta['public_page'] ?? [],
            config("webinars.{$page}.{$seriesSlug}.content", []),
        );
    }

    public function style(string $page, string $seriesSlug): array
    {
        return array_replace_recursive(
            config('webinars.style', []),
            config("webinars.{$page}.style", []),
            config("webinars.{$page}.{$seriesSlug}.style", []),
        );
    }
}