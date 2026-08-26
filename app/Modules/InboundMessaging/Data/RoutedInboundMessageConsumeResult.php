<?php

namespace App\Modules\InboundMessaging\Data;

use App\Modules\Core\Models\Contact;
use InvalidArgumentException;

final readonly class RoutedInboundMessageConsumeResult
{
    public const STATUS_HANDLED = 'handled';
    public const STATUS_UNRESOLVED = 'unresolved';

    private const STATUSES = [
        self::STATUS_HANDLED,
        self::STATUS_UNRESOLVED,
    ];

    public function __construct(
        public string $status,
        public ?Contact $relatedContact = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                "Unsupported routed inbound-message consume status [{$status}].",
            );
        }
    }

    public static function handled(
        ?Contact $relatedContact = null,
    ): self {
        return new self(
            status: self::STATUS_HANDLED,
            relatedContact: $relatedContact,
        );
    }

    public static function unresolved(
        ?Contact $relatedContact = null,
    ): self {
        return new self(
            status: self::STATUS_UNRESOLVED,
            relatedContact: $relatedContact,
        );
    }

    public function isHandled(): bool
    {
        return $this->status === self::STATUS_HANDLED;
    }
}