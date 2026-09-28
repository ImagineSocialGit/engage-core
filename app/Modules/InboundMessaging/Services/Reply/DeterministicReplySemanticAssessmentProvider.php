<?php

namespace App\Modules\InboundMessaging\Services\Reply;

use App\Modules\InboundMessaging\Contracts\ReplySemanticAssessmentProvider;
use App\Modules\InboundMessaging\Data\ReplySemanticAssessment;

final class DeterministicReplySemanticAssessmentProvider implements ReplySemanticAssessmentProvider
{
    private const SOURCE = 'deterministic_v1';

    /** @var array<string, array<int, string>> */
    private const DEFERRED_RULES = [
        'not_interested_right_now' => [
            'not interested right now',
            'not interested at the moment',
        ],
        'not_ready_yet' => [
            'not ready yet',
            'not ready right now',
            'not ready at the moment',
        ],
        'maybe_later' => [
            'maybe later',
            "i'll let you know later",
            'i will let you know later',
            "i'll reach out when i'm ready",
            'i will reach out when i am ready',
        ],
        'future_follow_up' => [
            'check back next month',
            'check back in a few months',
            'reach out next month',
            'reach out in a few months',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const HIGH_INTENT_RULES = [
        'direct_call_request' => [
            'can we talk',
            'could we talk',
            "let's talk",
            'call me',
            'can you call me',
            'please call me',
            'schedule a call',
            'set up a call',
            'book a call',
            'when can we talk',
        ],
        'appointment_request' => [
            'schedule an appointment',
            'book an appointment',
            'set up an appointment',
            'schedule a meeting',
            'book a meeting',
        ],
        'application_request' => [
            'ready to apply',
            'start the application',
            'start my application',
            'begin the application',
        ],
        'move_forward' => [
            'ready to move forward',
            "i'd like to move forward",
            'i would like to move forward',
            'what is the next step',
            'what are the next steps',
        ],
        'current_interest' => [
            'interested right now',
            "i'm interested",
            'i am interested',
            "i'd love to",
            'i would love to',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const NEGATIVE_RULES = [
        'not_interested' => [
            'not interested',
            'not for me',
        ],
        'decline' => [
            'no thanks',
            'no thank you',
        ],
        'do_not_contact' => [
            "don't contact me",
            'do not contact me',
            'remove me',
        ],
    ];

    /** @var array<int, string> */
    private const ROUTINE_EXACT = [
        'thanks',
        'thank you',
        'got it',
        'ok thanks',
        'okay thanks',
        'sounds good',
        'perfect thanks',
        'understood',
        'received',
        'will do',
    ];

    /** @var array<int, string> */
    private const SCHEDULING_CONSTRAINTS = [
        'not available this week',
        "can't this week",
        'cannot this week',
        'busy this week',
        'not free this week',
    ];

    public function assess(string $body): ReplySemanticAssessment
    {
        $text = $this->normalize($body);

        if ($text === '') {
            return $this->needsReview('empty_body');
        }

        $deferredRule = $this->matchingRule($text, self::DEFERRED_RULES);

        if ($deferredRule !== null) {
            return new ReplySemanticAssessment(
                category: ReplySemanticAssessment::CATEGORY_POSITIVE_DEFERRED,
                interest: $this->positiveInterest($text)
                    ? ReplySemanticAssessment::INTEREST_POSITIVE
                    : ReplySemanticAssessment::INTEREST_UNCLEAR,
                readiness: $this->containsAny($text, [
                    'not ready yet',
                    'not ready right now',
                    'not ready at the moment',
                ])
                    ? ReplySemanticAssessment::READINESS_NOT_READY
                    : ReplySemanticAssessment::READINESS_LATER,
                requestedAction: $this->requestedAction($text),
                constraint: ReplySemanticAssessment::CONSTRAINT_TIMING,
                confidence: ReplySemanticAssessment::CONFIDENCE_HIGH,
                source: self::SOURCE,
                ruleKey: $deferredRule,
            );
        }

        $highIntentRule = $this->matchingRule($text, self::HIGH_INTENT_RULES);

        if ($highIntentRule !== null) {
            $hasSchedulingConstraint = $this->containsAny(
                $text,
                self::SCHEDULING_CONSTRAINTS,
            );

            return new ReplySemanticAssessment(
                category: ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                interest: ReplySemanticAssessment::INTEREST_POSITIVE,
                readiness: $this->containsAny($text, [
                    'interested right now',
                    'ready to apply',
                    'ready to move forward',
                ])
                    ? ReplySemanticAssessment::READINESS_NOW
                    : ReplySemanticAssessment::READINESS_NEAR_TERM,
                requestedAction: $this->requestedAction($text),
                constraint: $hasSchedulingConstraint
                    ? ReplySemanticAssessment::CONSTRAINT_SCHEDULING
                    : ReplySemanticAssessment::CONSTRAINT_NONE,
                confidence: in_array($highIntentRule, [
                    'direct_call_request',
                    'appointment_request',
                    'application_request',
                    'move_forward',
                ], true)
                    ? ReplySemanticAssessment::CONFIDENCE_HIGH
                    : ReplySemanticAssessment::CONFIDENCE_MEDIUM,
                source: self::SOURCE,
                ruleKey: $hasSchedulingConstraint
                    ? 'scheduling_constraint_with_interest'
                    : $highIntentRule,
            );
        }

        $negativeRule = $this->matchingRule($text, self::NEGATIVE_RULES);

        if ($negativeRule !== null) {
            return new ReplySemanticAssessment(
                category: ReplySemanticAssessment::CATEGORY_NEGATIVE,
                interest: ReplySemanticAssessment::INTEREST_NEGATIVE,
                readiness: ReplySemanticAssessment::READINESS_UNKNOWN,
                requestedAction: ReplySemanticAssessment::ACTION_NONE,
                constraint: ReplySemanticAssessment::CONSTRAINT_NONE,
                confidence: ReplySemanticAssessment::CONFIDENCE_HIGH,
                source: self::SOURCE,
                ruleKey: $negativeRule,
            );
        }

        if (in_array($text, self::ROUTINE_EXACT, true)) {
            return new ReplySemanticAssessment(
                category: ReplySemanticAssessment::CATEGORY_ROUTINE,
                interest: ReplySemanticAssessment::INTEREST_NEUTRAL,
                readiness: ReplySemanticAssessment::READINESS_UNKNOWN,
                requestedAction: ReplySemanticAssessment::ACTION_NONE,
                constraint: ReplySemanticAssessment::CONSTRAINT_NONE,
                confidence: ReplySemanticAssessment::CONFIDENCE_HIGH,
                source: self::SOURCE,
                ruleKey: 'routine_acknowledgement',
            );
        }

        return $this->needsReview('no_confident_rule');
    }

    private function needsReview(string $ruleKey): ReplySemanticAssessment
    {
        return new ReplySemanticAssessment(
            category: ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
            interest: ReplySemanticAssessment::INTEREST_UNCLEAR,
            readiness: ReplySemanticAssessment::READINESS_UNKNOWN,
            requestedAction: ReplySemanticAssessment::ACTION_NONE,
            constraint: ReplySemanticAssessment::CONSTRAINT_NONE,
            confidence: ReplySemanticAssessment::CONFIDENCE_LOW,
            source: self::SOURCE,
            ruleKey: $ruleKey,
        );
    }

    /**
     * @param array<string, array<int, string>> $rules
     */
    private function matchingRule(string $text, array $rules): ?string
    {
        foreach ($rules as $ruleKey => $phrases) {
            if ($this->containsAny($text, $phrases)) {
                return $ruleKey;
            }
        }

        return null;
    }

    /** @param array<int, string> $phrases */
    private function containsAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $phrase = $this->normalize($phrase);

            if ($phrase === '') {
                continue;
            }

            $pattern = '/(?<![\\pL\\pN])'
                .preg_quote($phrase, '/')
                .'(?![\\pL\\pN])/u';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function positiveInterest(string $text): bool
    {
        if ($this->containsAny($text, [
            'not interested',
        ])) {
            return false;
        }

        return $this->containsAny($text, [
            "i'm interested",
            'i am interested',
            'interested right now',
            "i'd love to",
            'i would love to',
            'ready to move forward',
            'ready to apply',
        ]);
    }

    private function requestedAction(string $text): string
    {
        if ($this->containsAny($text, [
            'can we talk',
            'could we talk',
            "let's talk",
            'call me',
            'can you call me',
            'please call me',
            'schedule a call',
            'set up a call',
            'book a call',
            'when can we talk',
        ])) {
            return ReplySemanticAssessment::ACTION_CALL;
        }

        if ($this->containsAny($text, [
            'schedule an appointment',
            'book an appointment',
            'set up an appointment',
            'schedule a meeting',
            'book a meeting',
        ])) {
            return ReplySemanticAssessment::ACTION_APPOINTMENT;
        }

        if ($this->containsAny($text, [
            'ready to apply',
            'start the application',
            'start my application',
            'begin the application',
        ])) {
            return ReplySemanticAssessment::ACTION_APPLICATION;
        }

        if ($this->containsAny($text, [
            'move forward',
            'next step',
            'next steps',
        ])) {
            return ReplySemanticAssessment::ACTION_OTHER;
        }

        return ReplySemanticAssessment::ACTION_NONE;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["’", "‘"], "'", $value);
        $value = preg_replace("/[^\\pL\\pN']+/u", ' ', $value) ?? $value;
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}