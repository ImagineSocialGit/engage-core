<?php

namespace App\Support\ProjectState;

use InvalidArgumentException;
use JsonException;

class ProjectStateDocumentCodec
{
    /**
     * @param array<string, mixed> $document
     */
    public function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        try {
            $document = json_decode(
                $json,
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The uploaded project-state file is not valid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException(
                'The uploaded project-state file must contain a JSON object.'
            );
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function checksum(array $document): string
    {
        unset($document['checksum']);

        return 'sha256:'.hash(
            'sha256',
            json_encode(
                $document,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
            ),
        );
    }

}