<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Data\WebinarRegistrationImportRow;
use Generator;
use InvalidArgumentException;
use SplFileObject;

class WebinarRegistrationCsvReader
{
    private const REQUIRED_HEADERS = [
        'email',
    ];

    /**
     * @return Generator<int, WebinarRegistrationImportRow>
     */
    public function read(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Webinar registration import CSV [{$path}] is not readable.");
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = $this->headers($file);

        $rowNumber = 1;

        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $rowNumber++;

            if (! is_array($values) || $this->emptyRow($values)) {
                continue;
            }

            $values = array_pad($values, count($headers), null);
            $row = array_combine($headers, array_slice($values, 0, count($headers)));

            if (! is_array($row)) {
                throw new InvalidArgumentException(
                    "Unable to combine Webinar registration import CSV row [{$rowNumber}] with its headers."
                );
            }

            try {
                yield $rowNumber => WebinarRegistrationImportRow::fromArray($row);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(
                    "Webinar registration import CSV row [{$rowNumber}] is invalid: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function headers(SplFileObject $file): array
    {
        $rawHeaders = $file->fgetcsv();

        if (! is_array($rawHeaders) || $rawHeaders === [] || $this->emptyRow($rawHeaders)) {
            throw new InvalidArgumentException('Webinar registration import CSV does not contain a valid header row.');
        }

        $headers = array_map(
            fn (mixed $header): string => $this->normalizeHeader((string) $header),
            $rawHeaders,
        );

        if (in_array('', $headers, true)) {
            throw new InvalidArgumentException('Webinar registration import CSV contains an empty header.');
        }

        if (count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('Webinar registration import CSV contains duplicate normalized headers.');
        }

        foreach (self::REQUIRED_HEADERS as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                throw new InvalidArgumentException(
                    "Webinar registration import CSV is missing required header [{$requiredHeader}]."
                );
            }
        }

        return $headers;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function emptyRow(array $values): bool
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->isEmpty();
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }
}
