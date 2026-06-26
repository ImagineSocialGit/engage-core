<?php

namespace App\Modules\Webinars\Data;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class WebinarAttendanceRecord
{
    public function __construct(
        public readonly ?string $registrantId,
        public readonly ?string $email,
        public readonly string $status,
        public readonly ?int $duration,
        public readonly ?CarbonInterface $joinTime,
        public readonly ?CarbonInterface $leaveTime,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(array $record): self
    {
        return new self(
            registrantId: isset($record['registrant_id']) ? (string) $record['registrant_id'] : null,
            email: filled($record['email'] ?? null) ? mb_strtolower(trim((string) $record['email'])) : null,
            status: (string) ($record['status'] ?? 'attended'),
            duration: isset($record['duration']) ? (int) $record['duration'] : null,
            joinTime: self::dateTime($record['join_time'] ?? null),
            leaveTime: self::dateTime($record['leave_time'] ?? null),
            raw: is_array($record['raw'] ?? null) ? $record['raw'] : $record,
        );
    }

    private static function dateTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! filled($value)) {
            return null;
        }

        return Carbon::parse($value);
    }
}