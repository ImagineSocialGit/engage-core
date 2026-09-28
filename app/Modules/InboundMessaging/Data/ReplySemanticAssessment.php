<?php

namespace App\Modules\InboundMessaging\Data;

use InvalidArgumentException;

final readonly class ReplySemanticAssessment
{
    public const CATEGORY_HIGH_INTENT = 'high_intent';
    public const CATEGORY_POSITIVE_DEFERRED = 'positive_deferred';
    public const CATEGORY_NEGATIVE = 'negative';
    public const CATEGORY_ROUTINE = 'routine';
    public const CATEGORY_NEEDS_REVIEW = 'needs_review';

    public const INTEREST_POSITIVE = 'positive';
    public const INTEREST_NEGATIVE = 'negative';
    public const INTEREST_NEUTRAL = 'neutral';
    public const INTEREST_UNCLEAR = 'unclear';

    public const READINESS_NOW = 'now';
    public const READINESS_NEAR_TERM = 'near_term';
    public const READINESS_LATER = 'later';
    public const READINESS_NOT_READY = 'not_ready';
    public const READINESS_UNKNOWN = 'unknown';

    public const ACTION_CALL = 'call';
    public const ACTION_APPOINTMENT = 'appointment';
    public const ACTION_APPLICATION = 'application';
    public const ACTION_OTHER = 'other';
    public const ACTION_NONE = 'none';

    public const CONSTRAINT_SCHEDULING = 'scheduling';
    public const CONSTRAINT_TIMING = 'timing';
    public const CONSTRAINT_NONE = 'none';
    public const CONSTRAINT_OTHER = 'other';

    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    private const CATEGORIES = [
        self::CATEGORY_HIGH_INTENT,
        self::CATEGORY_POSITIVE_DEFERRED,
        self::CATEGORY_NEGATIVE,
        self::CATEGORY_ROUTINE,
        self::CATEGORY_NEEDS_REVIEW,
    ];

    private const INTERESTS = [
        self::INTEREST_POSITIVE,
        self::INTEREST_NEGATIVE,
        self::INTEREST_NEUTRAL,
        self::INTEREST_UNCLEAR,
    ];

    private const READINESS = [
        self::READINESS_NOW,
        self::READINESS_NEAR_TERM,
        self::READINESS_LATER,
        self::READINESS_NOT_READY,
        self::READINESS_UNKNOWN,
    ];

    private const ACTIONS = [
        self::ACTION_CALL,
        self::ACTION_APPOINTMENT,
        self::ACTION_APPLICATION,
        self::ACTION_OTHER,
        self::ACTION_NONE,
    ];

    private const CONSTRAINTS = [
        self::CONSTRAINT_SCHEDULING,
        self::CONSTRAINT_TIMING,
        self::CONSTRAINT_NONE,
        self::CONSTRAINT_OTHER,
    ];

    private const CONFIDENCES = [
        self::CONFIDENCE_HIGH,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_LOW,
    ];

    public function __construct(
        public string $category,
        public string $interest,
        public string $readiness,
        public string $requestedAction,
        public string $constraint,
        public string $confidence,
        public string $source,
        public ?string $ruleKey = null,
    ) {
        $this->assertAllowed($category, self::CATEGORIES, 'category');
        $this->assertAllowed($interest, self::INTERESTS, 'interest');
        $this->assertAllowed($readiness, self::READINESS, 'readiness');
        $this->assertAllowed($requestedAction, self::ACTIONS, 'requested action');
        $this->assertAllowed($constraint, self::CONSTRAINTS, 'constraint');
        $this->assertAllowed($confidence, self::CONFIDENCES, 'confidence');

        if (trim($source) === '') {
            throw new InvalidArgumentException(
                'Reply semantic assessment source cannot be blank.',
            );
        }

        if ($ruleKey !== null && trim($ruleKey) === '') {
            throw new InvalidArgumentException(
                'Reply semantic assessment rule key cannot be blank.',
            );
        }
    }

    /** @param array<int, string> $allowed */
    private function assertAllowed(
        string $value,
        array $allowed,
        string $label,
    ): void {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Unsupported reply semantic assessment {$label} [{$value}].",
            );
        }
    }
}