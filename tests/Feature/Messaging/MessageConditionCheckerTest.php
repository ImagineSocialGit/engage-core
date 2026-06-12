<?php

namespace Tests\Feature\Messaging;

use App\Services\Messaging\MessageConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MessageConditionCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_true_when_no_conditions_exist(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [],
            context: [],
        );

        $this->assertTrue($result);
    }

    public function test_it_matches_exact_values(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status' => 'new',
            ],
            context: [
                'contact' => [
                    'status' => 'new',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_fails_exact_value_match(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status' => 'converted',
            ],
            context: [
                'contact' => [
                    'status' => 'new',
                ],
            ],
        );

        $this->assertFalse($result);
    }

    public function test_it_supports_not_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status_not' => 'converted',
            ],
            context: [
                'contact' => [
                    'status' => 'new',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_in_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status_in' => [
                    'new',
                    'warm',
                ],
            ],
            context: [
                'contact' => [
                    'status' => 'warm',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_not_in_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status_not_in' => [
                    'converted',
                    'closed',
                ],
            ],
            context: [
                'contact' => [
                    'status' => 'warm',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_exists_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'webinar_registration.join_token_exists' => true,
            ],
            context: [
                'webinar_registration' => [
                    'join_token' => 'abc123',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_missing_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'webinar_registration.provider_registration_id_missing' => true,
            ],
            context: [
                'webinar_registration' => [
                    'join_token' => 'abc123',
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_truthy_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.opted_in_truthy' => true,
            ],
            context: [
                'contact' => [
                    'opted_in' => 1,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_falsy_operator(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.opted_in_falsy' => true,
            ],
            context: [
                'contact' => [
                    'opted_in' => false,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_greater_than(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.score_gt' => 50,
            ],
            context: [
                'contact' => [
                    'score' => 100,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_greater_than_or_equal(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.score_gte' => 100,
            ],
            context: [
                'contact' => [
                    'score' => 100,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_less_than(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.score_lt' => 10,
            ],
            context: [
                'contact' => [
                    'score' => 5,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_less_than_or_equal(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.score_lte' => 5,
            ],
            context: [
                'contact' => [
                    'score' => 5,
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_supports_nested_dot_paths(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'webinar.series.title' => 'Homebuyer',
            ],
            context: [
                'webinar' => [
                    'series' => [
                        'title' => 'Homebuyer',
                    ],
                ],
            ],
        );

        $this->assertTrue($result);
    }

    public function test_it_returns_false_when_any_condition_fails(): void
    {
        $result = app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status' => 'new',
                'contact.score_gt' => 100,
            ],
            context: [
                'contact' => [
                    'status' => 'new',
                    'score' => 10,
                ],
            ],
        );

        $this->assertFalse($result);
    }

    public function test_it_rejects_invalid_in_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MessageConditionChecker::class)->passes(
            conditions: [
                'contact.status_in' => 'new',
            ],
            context: [
                'contact' => [
                    'status' => 'new',
                ],
            ],
        );
    }
}