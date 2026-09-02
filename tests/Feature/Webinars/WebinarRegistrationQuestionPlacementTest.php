<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Tests\TestCase;

class WebinarRegistrationQuestionPlacementTest extends TestCase
{
    public function test_questions_default_to_registration_and_can_be_resolved_for_post_registration(): void
    {
        $resolver = app(WebinarRegistrationQuestionResolver::class);
        $definitions = [
            [
                'key' => 'initial_interest',
                'label' => 'Choose one',
                'type' => 'select',
                'required' => true,
                'definition_version' => 'v1',
                'options' => [
                    ['key' => 'one', 'label' => 'One'],
                ],
            ],
            [
                'key' => 'follow_up_question',
                'label' => 'What should we cover?',
                'type' => 'textarea',
                'placement' => 'post_registration',
                'required' => true,
                'max_length' => 1200,
                'definition_version' => 'v2',
            ],
        ];

        $initial = $resolver->resolveForPlacement(
            $definitions,
            WebinarRegistrationQuestionResolver::PLACEMENT_REGISTRATION,
        );
        $post = $resolver->resolveForPlacement(
            $definitions,
            WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION,
        );

        $this->assertSame(['initial_interest'], array_column($initial, 'key'));
        $this->assertSame(['follow_up_question'], array_column($post, 'key'));
        $this->assertSame(
            WebinarRegistrationQuestionResolver::PLACEMENT_REGISTRATION,
            $initial[0]['placement'],
        );
        $this->assertSame(
            WebinarRegistrationQuestionResolver::TYPE_TEXTAREA,
            $post[0]['type'],
        );
        $this->assertSame(1200, $post[0]['max_length']);
    }

    public function test_textarea_validation_and_snapshots_follow_the_configured_required_and_max_length_contract(): void
    {
        $resolver = app(WebinarRegistrationQuestionResolver::class);
        $questions = $resolver->resolveForPlacement([
            [
                'key' => 'open_question',
                'label' => 'What should we cover?',
                'type' => 'textarea',
                'placement' => 'post_registration',
                'required' => true,
                'max_length' => 25,
                'definition_version' => 'v3',
            ],
        ], WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION);

        $missing = Validator::make(
            [],
            $resolver->validationRules($questions, null),
            $resolver->validationMessages($questions),
        );
        $this->assertTrue($missing->fails());

        $tooLongAnswers = [
            'open_question' => [
                'answer' => str_repeat('x', 26),
            ],
        ];
        $tooLong = Validator::make(
            ['registration_questions' => $tooLongAnswers],
            $resolver->validationRules($questions, $tooLongAnswers),
            $resolver->validationMessages($questions),
        );
        $this->assertTrue($tooLong->fails());

        $answers = [
            'open_question' => [
                'answer' => 'Please cover budgeting.',
            ],
        ];
        $valid = Validator::make(
            ['registration_questions' => $answers],
            $resolver->validationRules($questions, $answers),
            $resolver->validationMessages($questions),
        );
        $this->assertFalse($valid->fails());

        $this->assertSame([
            [
                'question_key' => 'open_question',
                'question_label' => 'What should we cover?',
                'question_type' => 'textarea',
                'answer_key' => 'text',
                'answer_label' => 'Open response',
                'answer_text' => 'Please cover budgeting.',
                'definition_version' => 'v3',
                'sort_order' => 10,
            ],
        ], $resolver->responseSnapshots($questions, $answers));
    }

    public function test_unknown_question_placement_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(WebinarRegistrationQuestionResolver::class)->resolve([
            [
                'key' => 'bad_placement',
                'label' => 'Bad placement?',
                'type' => 'textarea',
                'placement' => 'after_everything',
                'required' => false,
                'definition_version' => 'v1',
            ],
        ]);
    }
}