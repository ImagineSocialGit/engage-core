<?php

namespace App\Modules\Messaging\Data\Email;

final readonly class EmailHygieneResult
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public string $email,
        public string $status,
        public string $reason,
    ) {}

    public static function valid(string $email): self
    {
        return new self($email, self::STATUS_VALID, 'mail_route_present');
    }

    public static function invalid(string $email, string $reason): self
    {
        return new self($email, self::STATUS_INVALID, $reason);
    }

    public static function suppressed(string $email): self
    {
        return new self($email, self::STATUS_SUPPRESSED, 'active_suppression');
    }

    public static function unknown(string $email): self
    {
        return new self($email, self::STATUS_UNKNOWN, 'dns_unavailable');
    }

    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }
}