<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationQuestionConfigTest extends TestCase
{
    public function test_each_series_resolves_its_own_complete_question_list(): void
    {
        $expectations = [
            'series-alpha' => [
                'question_key' => 'primary_interest',
                'question_label' => 'Which topic matters most?',
                'definition_version' => 'test_alpha_v1',
                'options' => [
                    'topic_one' => 'Topic One',
                    'topic_two' => 'Topic Two',
                    'other' => 'Other',
                ],
            ],
            'series-beta' => [
                'question_key' => 'primary_concern',
                'question_label' => 'Which concern matters most?',
                'definition_version' => 'test_beta_v1',
                'options' => [
                    'concern_one' => 'Concern One',
                    'concern_two' => 'Concern Two',
                    'other' => 'Other',
                ],
            ],
            'series-gamma' => [
                'question_key' => 'primary_goal',
                'question_label' => 'Which goal matters most?',
                'definition_version' => 'test_gamma_v1',
                'options' => [
                    'goal_one' => 'Goal One',
                    'goal_two' => 'Goal Two',
                    'other' => 'Other',
                ],
            ],
        ];

        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'registration' => [
                'questions' => [
                    $this->questionDefinition(
                        key: 'shared_question',
                        label: 'Shared fallback question?',
                        options: [
                            'shared_option' => 'Shared Option',
                            'other' => 'Other',
                        ],
                        definitionVersion: 'shared_v1',
                    ),
                ],
            ],
        ]);

        foreach ($expectations as $seriesSlug => $expected) {
            $this->configureSeriesQuestion(
                seriesSlug: $seriesSlug,
                definition: $this->questionDefinition(
                    key: $expected['question_key'],
                    label: $expected['question_label'],
                    options: $expected['options'],
                    definitionVersion: $expected['definition_version'],
                ),
            );
        }

        foreach ($expectations as $seriesSlug => $expected) {
            $questions = $this->resolvedQuestions($seriesSlug);

            $this->assertCount(1, $questions, $seriesSlug);

            $question = $questions[0];

            $this->assertSame(
                $expected['question_key'],
                $question['key'],
                $seriesSlug,
            );

            $this->assertSame(
                $expected['question_label'],
                $question['label'],
                $seriesSlug,
            );

            $this->assertTrue($question['required'], $seriesSlug);
            $this->assertSame('select', $question['type'], $seriesSlug);

            $this->assertSame(
                $expected['definition_version'],
                $question['definition_version'],
                $seriesSlug,
            );

            $this->assertSame(
                array_keys($expected['options']),
                array_column($question['options'], 'key'),
                $seriesSlug,
            );

            $this->assertSame(
                array_values($expected['options']),
                array_column($question['options'], 'label'),
                $seriesSlug,
            );

            $this->assertSame(
                'other',
                $question['other']['option_key'],
                $seriesSlug,
            );

            $this->assertTrue(
                $question['other']['required'],
                $seriesSlug,
            );

            $this->assertSame(
                500,
                $question['other']['max_length'],
                $seriesSlug,
            );

            $this->assertNotContains(
                'shared_option',
                array_column($question['options'], 'key'),
                $seriesSlug,
            );
        }
    }

    public function test_shared_registration_content_may_omit_question_definitions(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'registration' => [
                'questions_section' => [
                    'enabled' => true,
                    'title' => 'Configured Question Section',
                    'body' => 'Configured supporting copy.',
                ],
            ],
        ]);

        $this->configureSeriesQuestion(
            seriesSlug: 'series-with-question',
            definition: $this->questionDefinition(
                key: 'configured_question',
                label: 'Configured question?',
                options: [
                    'configured_answer' => 'Configured Answer',
                    'other' => 'Other',
                ],
                definitionVersion: 'test_v1',
            ),
        );

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'series-with-question',
            seriesMeta: [],
        );

        $questions = app(
            WebinarRegistrationQuestionResolver::class,
        )->resolve(
            data_get($content, 'registration.questions'),
        );

        $this->assertSame(
            'Configured Question Section',
            data_get(
                $content,
                'registration.questions_section.title',
            ),
        );

        $this->assertSame(
            'Configured supporting copy.',
            data_get(
                $content,
                'registration.questions_section.body',
            ),
        );

        $this->assertCount(1, $questions);
        $this->assertSame('configured_question', $questions[0]['key']);

        $this->assertSame(
            ['configured_answer', 'other'],
            array_column($questions[0]['options'], 'key'),
        );
    }

    public function test_answer_keys_can_be_reused_without_becoming_global_requirements(): void
    {
        Config::set('webinars.content', []);
        Config::set('webinars.register.content', [
            'registration' => [
                'questions' => [],
            ],
        ]);

        $definitions = [
            'series-one' => $this->questionDefinition(
                key: 'series_one_question',
                label: 'Series one question?',
                options: [
                    'shared_interest' => 'Shared Interest',
                    'series_one_only' => 'Series One Only',
                    'other' => 'Other',
                ],
                definitionVersion: 'series_one_v1',
            ),
            'series-two' => $this->questionDefinition(
                key: 'series_two_question',
                label: 'Series two question?',
                options: [
                    'shared_interest' => 'Shared Interest',
                    'series_two_only' => 'Series Two Only',
                    'other' => 'Other',
                ],
                definitionVersion: 'series_two_v1',
            ),
            'series-three' => $this->questionDefinition(
                key: 'series_three_question',
                label: 'Series three question?',
                options: [
                    'series_three_only' => 'Series Three Only',
                    'other' => 'Other',
                ],
                definitionVersion: 'series_three_v1',
            ),
        ];

        foreach ($definitions as $seriesSlug => $definition) {
            $this->configureSeriesQuestion(
                seriesSlug: $seriesSlug,
                definition: $definition,
            );
        }

        $answersBySeries = [];

        foreach (array_keys($definitions) as $seriesSlug) {
            $questions = $this->resolvedQuestions($seriesSlug);

            $this->assertCount(1, $questions, $seriesSlug);

            $answersBySeries[$seriesSlug] = array_column(
                $questions[0]['options'],
                'key',
            );
        }

        $this->assertContains(
            'shared_interest',
            $answersBySeries['series-one'],
        );

        $this->assertContains(
            'shared_interest',
            $answersBySeries['series-two'],
        );

        $this->assertNotContains(
            'shared_interest',
            $answersBySeries['series-three'],
        );

        $this->assertContains(
            'series_one_only',
            $answersBySeries['series-one'],
        );

        $this->assertNotContains(
            'series_one_only',
            $answersBySeries['series-two'],
        );

        $this->assertNotContains(
            'series_one_only',
            $answersBySeries['series-three'],
        );

        $this->assertContains(
            'series_two_only',
            $answersBySeries['series-two'],
        );

        $this->assertContains(
            'series_three_only',
            $answersBySeries['series-three'],
        );

        foreach ($answersBySeries as $seriesSlug => $answerKeys) {
            $this->assertContains('other', $answerKeys, $seriesSlug);
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function configureSeriesQuestion(
        string $seriesSlug,
        array $definition,
    ): void {
        Config::set(
            "webinars.register.{$seriesSlug}.content",
            [
                'registration' => [
                    'questions' => [$definition],
                ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvedQuestions(string $seriesSlug): array
    {
        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: $seriesSlug,
            seriesMeta: [],
        );

        return app(
            WebinarRegistrationQuestionResolver::class,
        )->resolve(
            data_get($content, 'registration.questions'),
        );
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function questionDefinition(
        string $key,
        string $label,
        array $options,
        string $definitionVersion,
    ): array {
        $optionDefinitions = [];

        foreach ($options as $optionKey => $optionLabel) {
            $optionDefinitions[] = [
                'key' => $optionKey,
                'label' => $optionLabel,
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'required' => true,
            'placeholder' => 'Choose one',
            'definition_version' => $definitionVersion,
            'options' => $optionDefinitions,
            'other' => [
                'option_key' => 'other',
                'label' => 'Tell us more',
                'placeholder' => 'Add more information',
                'required' => true,
                'max_length' => 500,
            ],
        ];
    }
}