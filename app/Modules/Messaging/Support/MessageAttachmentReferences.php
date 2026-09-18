<?php

namespace App\Modules\Messaging\Support;

use InvalidArgumentException;

final class MessageAttachmentReferences
{
    public const MAX_COUNT = 5;

    /** @return array<int, array{source: string, id: string}> */
    public static function normalize(mixed $references): array
    {
        if (! is_array($references) || ! array_is_list($references) || count($references) > self::MAX_COUNT) {
            throw new InvalidArgumentException('Email attachments must be a list of at most five references.');
        }

        $normalized = [];
        $seen = [];
        foreach ($references as $reference) {
            if (! is_array($reference) || array_is_list($reference)
                || array_diff(array_keys($reference), ['source', 'id']) !== []
                || ! is_string($reference['source'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reference['source']) !== 1
                || ! is_string($reference['id'] ?? null)
                || preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $reference['id']) !== 1) {
                throw new InvalidArgumentException('Each attachment requires a valid source and stable string ID.');
            }

            $key = $reference['source'].':'.$reference['id'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('The same attachment cannot be added twice.');
            }
            $seen[$key] = true;
            $normalized[] = ['source' => $reference['source'], 'id' => $reference['id']];
        }

        return $normalized;
    }
}