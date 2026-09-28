<?php

namespace App\Modules\Campaigns\Data;

use InvalidArgumentException;

final class CampaignContactResultOperation
{
    public const ENROLL = 'enroll';
    public const REENROLL_FROM_MESSAGE = 'reenroll_from_message';
    public const EXCLUDE_ALLOCATION_MESSAGE = 'exclude_allocation_message';
    public const REMOVE_ALLOCATION_MESSAGE_EXCLUSION = 'remove_allocation_message_exclusion';
    public const ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION = 'enroll_with_allocation_message_exclusion';

    public const VALUES = [
        self::ENROLL,
        self::REENROLL_FROM_MESSAGE,
        self::EXCLUDE_ALLOCATION_MESSAGE,
        self::REMOVE_ALLOCATION_MESSAGE_EXCLUSION,
        self::ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION,
    ];

    public static function normalize(string $operation): string
    {
        $operation = trim($operation);

        if (! in_array($operation, self::VALUES, true)) {
            throw new InvalidArgumentException(
                "Unsupported Campaign Contact-result operation [{$operation}].",
            );
        }

        return $operation;
    }

    public static function requiresMessageStep(string $operation): bool
    {
        $operation = self::normalize($operation);

        return in_array($operation, [
            self::REENROLL_FROM_MESSAGE,
            self::EXCLUDE_ALLOCATION_MESSAGE,
            self::REMOVE_ALLOCATION_MESSAGE_EXCLUSION,
            self::ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION,
        ], true);
    }

    public static function requiresRecurringAllocation(string $operation): bool
    {
        $operation = self::normalize($operation);

        return in_array($operation, [
            self::EXCLUDE_ALLOCATION_MESSAGE,
            self::REMOVE_ALLOCATION_MESSAGE_EXCLUSION,
            self::ENROLL_WITH_ALLOCATION_MESSAGE_EXCLUSION,
        ], true);
    }
}