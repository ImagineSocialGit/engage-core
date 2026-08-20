<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactImportPostProcessResult
{
    public const STATE_APPLIED = 'applied';
    public const STATE_PARTIAL = 'partial';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_FAILED = 'failed';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $state,
        public ?string $reasonCode = null,
        public ?string $message = null,
        public array $meta = [],
    ) {}

    /** @param array<string, mixed> $meta */
    public static function applied(array $meta = [], ?string $message = null): self
    {
        return new self(self::STATE_APPLIED, message: $message, meta: $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function partial(string $reasonCode, string $message, array $meta = []): self
    {
        return new self(self::STATE_PARTIAL, $reasonCode, $message, $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function skipped(string $reasonCode, string $message, array $meta = []): self
    {
        return new self(self::STATE_SKIPPED, $reasonCode, $message, $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function blocked(string $reasonCode, string $message, array $meta = []): self
    {
        return new self(self::STATE_BLOCKED, $reasonCode, $message, $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function failed(string $reasonCode, string $message, array $meta = []): self
    {
        return new self(self::STATE_FAILED, $reasonCode, $message, $meta);
    }

    public function reviewRequired(): bool
    {
        return in_array($this->state, [
            self::STATE_PARTIAL,
            self::STATE_SKIPPED,
            self::STATE_BLOCKED,
            self::STATE_FAILED,
        ], true);
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        return [
            'state' => $this->state,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'review_required' => $this->reviewRequired(),
            'meta' => $this->meta,
        ];
    }
}