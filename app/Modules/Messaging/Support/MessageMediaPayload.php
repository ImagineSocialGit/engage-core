<?php

namespace App\Modules\Messaging\Support;

use InvalidArgumentException;

final class MessageMediaPayload
{
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_DOCUMENT = 'document';
    public const KIND_FILE = 'file';

    public const KINDS = [
        self::KIND_IMAGE,
        self::KIND_VIDEO,
        self::KIND_AUDIO,
        self::KIND_DOCUMENT,
        self::KIND_FILE,
    ];

    public const TRACKING_KEY = 'media_primary';

    /**
     * Authoritative email-media display-size catalog.
     *
     * Add or change display sizes here only. Validation, authoring options,
     * config contracts, and email rendering all derive from this catalog.
     *
     * @var array<string, array{label: string, width: int}>
     */
    private const DISPLAY_SIZE_OPTIONS = [
        'thumbnail' => [
            'label' => 'Thumbnail',
            'width' => 96,
        ],
        'extra_small' => [
            'label' => 'Extra small',
            'width' => 160,
        ],
        'small' => [
            'label' => 'Small',
            'width' => 240,
        ],
        'medium' => [
            'label' => 'Medium',
            'width' => 360,
        ],
        'large' => [
            'label' => 'Large',
            'width' => 480,
        ],
        'full' => [
            'label' => 'Full width',
            'width' => 576,
        ],
    ];

    /** @return array<int, string> */
    public static function displaySizes(): array
    {
        return array_keys(self::DISPLAY_SIZE_OPTIONS);
    }

    /** @return array<string, array{label: string, width: int}> */
    public static function displaySizeOptions(): array
    {
        return self::DISPLAY_SIZE_OPTIONS;
    }

    public static function displayWidth(mixed $size): int
    {
        $size = is_string($size) ? trim($size) : '';

        return self::DISPLAY_SIZE_OPTIONS[$size]['width']
            ?? self::DISPLAY_SIZE_OPTIONS['full']['width'];
    }

    /**
     * @return array<int, array{path: string, message: string}>
     */
    public static function validationErrors(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [[
                'path' => '',
                'message' => 'Media must be a keyed payload.',
            ]];
        }

        $errors = [];
        $allowed = [
            'asset_uuid',
            'kind',
            'title',
            'url',
            'mime_type',
            'poster_asset_uuid',
            'poster_url',
            'playback_url',
            'display_size',
            'tracking_key',
        ];
        $unsupported = array_values(array_diff(array_keys($value), $allowed));

        if ($unsupported !== []) {
            $errors[] = [
                'path' => (string) $unsupported[0],
                'message' => 'Media contains an unsupported field.',
            ];
        }

        foreach (['asset_uuid', 'kind', 'title', 'url'] as $required) {
            if (! self::filledString($value[$required] ?? null)) {
                $errors[] = [
                    'path' => $required,
                    'message' => "Media requires [{$required}].",
                ];
            }
        }

        if (self::filledString($value['asset_uuid'] ?? null)
            && ! self::isUuid((string) $value['asset_uuid'])
        ) {
            $errors[] = [
                'path' => 'asset_uuid',
                'message' => 'Media asset_uuid must be a UUID.',
            ];
        }

        $kind = self::filledString($value['kind'] ?? null)
            ? trim((string) $value['kind'])
            : null;

        if ($kind !== null && ! in_array($kind, self::KINDS, true)) {
            $errors[] = [
                'path' => 'kind',
                'message' => 'Media kind is not supported.',
            ];
        }

        if (self::filledString($value['url'] ?? null)
            && ! CtaTrackingLinkGenerator::isTrackableDestination($value['url'])
        ) {
            $errors[] = [
                'path' => 'url',
                'message' => 'Media URL must be an absolute HTTP or HTTPS URL.',
            ];
        }

        $playbackUrl = self::filledString($value['playback_url'] ?? null)
            ? trim((string) $value['playback_url'])
            : null;

        if ($playbackUrl !== null
            && ! CtaTrackingLinkGenerator::isTrackableDestination($playbackUrl)
        ) {
            $errors[] = [
                'path' => 'playback_url',
                'message' => 'Media playback_url must be an absolute HTTP or HTTPS URL.',
            ];
        }

        if ($playbackUrl !== null && $kind !== self::KIND_VIDEO) {
            $errors[] = [
                'path' => 'playback_url',
                'message' => 'Media playback_url may only be attached to video media.',
            ];
        }

        if (array_key_exists('mime_type', $value)
            && $value['mime_type'] !== null
            && ! is_string($value['mime_type'])
        ) {
            $errors[] = [
                'path' => 'mime_type',
                'message' => 'Media mime_type must be text or null.',
            ];
        }

        if (array_key_exists('tracking_key', $value)
            && $value['tracking_key'] !== null
            && ! CtaTrackingLinkGenerator::isValidTrackingKey($value['tracking_key'])
        ) {
            $errors[] = [
                'path' => 'tracking_key',
                'message' => 'Media tracking_key must be a stable lowercase tracking key of at most 96 characters.',
            ];
        }

        if (array_key_exists('display_size', $value)) {
            if (! is_string($value['display_size'])
                || ! in_array($value['display_size'], self::displaySizes(), true)) {
                $errors[] = [
                    'path' => 'display_size',
                    'message' => 'Media display_size must use a supported media size.',
                ];
            } elseif (! in_array($kind, [self::KIND_IMAGE, self::KIND_VIDEO], true)) {
                $errors[] = [
                    'path' => 'display_size',
                    'message' => 'Media sizing applies only to images and video.',
                ];
            }
        }

        $posterUuid = self::filledString($value['poster_asset_uuid'] ?? null)
            ? trim((string) $value['poster_asset_uuid'])
            : null;
        $posterUrl = self::filledString($value['poster_url'] ?? null)
            ? trim((string) $value['poster_url'])
            : null;

        if ($posterUuid !== null && $posterUrl === null) {
            $errors[] = [
                'path' => 'poster_url',
                'message' => 'Media poster_asset_uuid requires poster_url.',
            ];
        }

        if ($posterUuid !== null && ! self::isUuid($posterUuid)) {
            $errors[] = [
                'path' => 'poster_asset_uuid',
                'message' => 'Media poster_asset_uuid must be a UUID.',
            ];
        }

        if ($posterUrl !== null
            && ! CtaTrackingLinkGenerator::isTrackableDestination($posterUrl)
        ) {
            $errors[] = [
                'path' => 'poster_url',
                'message' => 'Media poster_url must be an absolute HTTP or HTTPS URL.',
            ];
        }

        if (($posterUuid !== null || $posterUrl !== null)
            && $kind !== self::KIND_VIDEO
        ) {
            $errors[] = [
                'path' => 'poster_asset_uuid',
                'message' => 'A poster image may only be attached to video media.',
            ];
        }

        return $errors;
    }

    public static function assertValid(mixed $value, string $field = 'media'): void
    {
        $error = self::validationErrors($value)[0] ?? null;

        if ($error === null) {
            return;
        }

        $path = trim((string) ($error['path'] ?? ''));
        $qualified = $path !== '' ? $field.'.'.$path : $field;

        throw new InvalidArgumentException(
            "Composition field [{$qualified}] ".lcfirst((string) $error['message']),
        );
    }

    public static function valid(mixed $value): bool
    {
        return self::validationErrors($value) === [];
    }

    private static function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value),
        ) === 1;
    }
}