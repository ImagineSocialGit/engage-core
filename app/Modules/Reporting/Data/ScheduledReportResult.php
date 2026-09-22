<?php

namespace App\Modules\Reporting\Data;

final class ScheduledReportResult
{
    /**
     * @param array<int, string> $body
     * @param array<string, string|int|float|null> $details
     * @param array{label: string, url: string}|null $cta
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $headline,
        public readonly string $preheader,
        public readonly array $body,
        public readonly array $details = [],
        public readonly ?array $cta = null,
        public readonly array $meta = [],
    ) {}

    /** @return array<string, mixed> */
    public function internalNotificationContent(): array
    {
        return array_filter([
            'subject' => $this->subject,
            'headline' => $this->headline,
            'preheader' => $this->preheader,
            'body' => $this->body,
            'details' => $this->details,
            'cta' => $this->cta,
            'meta' => $this->meta,
        ], static fn (mixed $value): bool => $value !== null);
    }
}