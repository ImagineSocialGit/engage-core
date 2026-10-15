<?php

namespace App\Modules\Events\Data;

use InvalidArgumentException;

final readonly class EventWorkspaceAction
{
    public const STYLE_PRIMARY = 'primary';

    public const STYLE_SECONDARY = 'secondary';

    public const STYLES = [
        self::STYLE_PRIMARY,
        self::STYLE_SECONDARY,
    ];

    public string $label;

    public string $url;

    public string $style;

    public function __construct(
        string $label,
        string $url,
        string $style = self::STYLE_SECONDARY,
        public bool $newTab = false,
    ) {
        $label = trim($label);
        $url = trim($url);
        $style = strtolower(trim($style));

        if ($label === '') {
            throw new InvalidArgumentException(
                'Event workspace action label must be non-empty.',
            );
        }

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidArgumentException(
                'Event workspace action URL must be non-empty and cannot contain control characters.',
            );
        }

        if (! str_starts_with($url, '/')) {
            $parts = parse_url($url);
            $scheme = is_array($parts)
                ? strtolower((string) ($parts['scheme'] ?? ''))
                : '';
            $host = is_array($parts)
                ? trim((string) ($parts['host'] ?? ''))
                : '';

            if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new InvalidArgumentException(
                    'Event workspace action URL must be root-relative or an absolute HTTP/HTTPS URL.',
                );
            }
        }

        if (! in_array($style, self::STYLES, true)) {
            throw new InvalidArgumentException(
                "Unsupported Event workspace action style [{$style}].",
            );
        }

        $this->label = $label;
        $this->url = $url;
        $this->style = $style;
    }
}