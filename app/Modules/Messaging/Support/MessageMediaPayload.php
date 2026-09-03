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

        $posterUuid = self::filledString($value['poster_asset_uuid'] ?? null)
            ? trim((string) $value['poster_asset_uuid'])
            : null;
        $posterUrl = self::filledString($value['poster_url'] ?? null)
            ? trim((string) $value['poster_url'])
            : null;

        if (($posterUuid === null) !== ($posterUrl === null)) {
            $errors[] = [
                'path' => 'poster_url',
                'message' => 'Media poster_asset_uuid and poster_url must be supplied together.',
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