<?php

namespace App\Modules\Webinars\Data;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, WebinarAttendanceRecord>
 */
final readonly class ProviderAttendanceSnapshot implements Countable, IteratorAggregate
{
    /**
     * @param array<int, WebinarAttendanceRecord> $records
     */
    private function __construct(
        public array $records,
        public bool $authoritative,
        public ?string $reason,
    ) {}

    /**
     * @param iterable<WebinarAttendanceRecord|array<string, mixed>> $records
     */
    public static function authoritative(iterable $records): self
    {
        return new self(
            records: self::normalizeRecords($records),
            authoritative: true,
            reason: null,
        );
    }

    /**
     * @param iterable<WebinarAttendanceRecord|array<string, mixed>> $records
     */
    public static function nonAuthoritative(iterable $records, string $reason): self
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A non-authoritative provider attendance snapshot requires a reason.');
        }

        return new self(
            records: self::normalizeRecords($records),
            authoritative: false,
            reason: $reason,
        );
    }

    /**
     * @return Traversable<int, WebinarAttendanceRecord>
     */
    public function getIterator(): Traversable
    {
        yield from $this->records;
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @param iterable<WebinarAttendanceRecord|array<string, mixed>> $records
     * @return array<int, WebinarAttendanceRecord>
     */
    private static function normalizeRecords(iterable $records): array
    {
        $normalized = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                $record = WebinarAttendanceRecord::fromArray($record);
            }

            if (! $record instanceof WebinarAttendanceRecord) {
                throw new InvalidArgumentException(sprintf(
                    'Provider attendance snapshots may contain only [%s] instances or attendance arrays.',
                    WebinarAttendanceRecord::class,
                ));
            }

            $normalized[] = $record;
        }

        return $normalized;
    }
}
