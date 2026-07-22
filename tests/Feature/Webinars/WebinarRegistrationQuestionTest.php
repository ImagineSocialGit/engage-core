<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Actions\ReplaceWebinarOccurrenceAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarRegistrationResponse;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\ContactPanels\WebinarContactPanelProvider;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class WebinarRegistrationQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();

        $this->configureWebinarRegistrationChannelAvailability();
        Config::set('webinars.register.content.registration.consents', [
            'transactional' => [
                'email' => true,
                'sms' => false,
                'required_channels' => ['email'],
            ],
            'marketing' => [
                'email' => false,
                'sms' => false,
            ],
        ]);
    }

    public function test_series_question_list_replaces_shared_question_list_atomically(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'series-question-override',
        ]);

        Config::set(
            'webinars.register.content.registration.questions',
            [$this->questionDefinition('shared_question', 'Shared question?')],
        );
        Config::set(
            "webinars.register.{$series->slug}.content.registration.questions",
            [$this->questionDefinition('series_question', 'Series question?')],
        );

        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: $series->slug,
            seriesMeta: [],
        );

        $questions = data_get($content, 'registration.questions');

        $this->assertIsArray($questions);
        $this->assertCount(1, $questions);
        $this->assertSame('series_question', $questions[0]['key']);
        $this->assertSame(
            ['affordability', 'other'],
            array_column($questions[0]['options'], 'key'),
        );
    }

    public function test_registration_page_renders_configured_select_and_other_fields(): void
    {
        [$series] = $this->occurrence('question-rendering');
        $this->configureSeriesQuestions($series);

        $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->assertSee('What is your biggest question?', true)
            ->assertSee(
                'name="registration_questions[primary_question][answer]"',
                false,
            )
            ->assertSee('value="affordability"', false)
            ->assertSee('How much can I afford?', true)
            ->assertSee(
                'name="registration_questions[primary_question][other]"',
                false,
            )
            ->assertSee('maxlength="500"', false);
    }

    public function test_required_questions_reject_missing_unknown_and_invalid_answers(): void
    {
        [$series, $webinar] = $this->occurrence('question-validation');
        $this->configureSeriesQuestions($series);

        $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload(),
            )
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors('registration_questions');

        $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload([
                    'registration_questions' => [
                        'primary_question' => [
                            'answer' => 'not_configured',
                        ],
                    ],
                ]),
            )
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors(
                'registration_questions.primary_question.answer',
            );

        $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload([
                    'registration_questions' => [
                        'primary_question' => [
                            'answer' => 'affordability',
                        ],
                        'unknown_question' => [
                            'answer' => 'anything',
                        ],
                    ],
                ]),
            )
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors('registration_questions');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('webinar_registration_responses', 0);
    }

    public function test_other_answer_requires_text_and_persists_trusted_snapshots(): void
    {
        [$series, $webinar] = $this->occurrence('question-other');
        $this->configureSeriesQuestions($series);

        $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload([
                    'registration_questions' => [
                        'primary_question' => [
                            'answer' => 'other',
                            'other' => '',
                        ],
                    ],
                ]),
            )
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors(
                'registration_questions.primary_question.other',
            );

        $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload([
                    'registration_questions' => [
                        'primary_question' => [
                            'answer' => 'other',
                            'other' => str_repeat('x', 501),
                        ],
                    ],
                ]),
            )
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors(
                'registration_questions.primary_question.other',
            );

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(
                $this->registrationUrl($series, $webinar),
                $this->registrationPayload([
                    'registration_questions' => [
                        'primary_question' => [
                            'answer' => 'other',
                            'other' => '  I need help understanding closing costs.  ',
                        ],
                    ],
                ]),
            );

        $response->assertRedirect();

        $registration = WebinarRegistration::query()->sole();

        $this->assertDatabaseHas('webinar_registration_responses', [
            'webinar_registration_id' => $registration->getKey(),
            'question_key' => 'primary_question',
            'question_label' => 'What is your biggest question?',
            'question_type' => 'select',
            'answer_key' => 'other',
            'answer_label' => 'Other',
            'answer_text' => 'I need help understanding closing costs.',
            'definition_version' => '2026_07',
            'sort_order' => 10,
        ]);
        $this->assertDatabaseCount('webinar_registration_responses', 1);
    }

    public function test_idempotent_registration_updates_one_response_row(): void
    {
        [$series, $webinar] = $this->occurrence('question-idempotency');
        $this->configureSeriesQuestions($series);

        $contact = Contact::factory()->create([
            'email' => 'jeff@example.com',
        ]);
        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'meta' => [],
        ]);

        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->twice()->andReturn(null);
        app()->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $this->post(
            $this->registrationUrl($series, $webinar),
            $this->registrationPayload([
                'registration_questions' => [
                    'primary_question' => [
                        'answer' => 'affordability',
                    ],
                ],
            ]),
        )->assertRedirect();

        $this->post(
            $this->registrationUrl($series, $webinar),
            $this->registrationPayload([
                'registration_questions' => [
                    'primary_question' => [
                        'answer' => 'other',
                        'other' => 'Credit qualification',
                    ],
                ],
            ]),
        )->assertRedirect();

        $response = WebinarRegistrationResponse::query()->sole();

        $this->assertSame(
            (int) $registration->getKey(),
            (int) $response->webinar_registration_id,
        );
        $this->assertSame('other', $response->answer_key);
        $this->assertSame('Credit qualification', $response->answer_text);
        $this->assertDatabaseCount('webinar_registration_responses', 1);
    }

    public function test_occurrence_replacement_does_not_duplicate_source_responses(): void
    {
        $series = WebinarSeries::factory()->create();
        $source = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $replacement = Webinar::factory()->meeting()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        $sourceRegistration = WebinarRegistration::factory()
            ->for(Contact::factory())
            ->for($source)
            ->create([
                'status' => 'registered',
                'cancelled_at' => null,
                'meta' => [
                    'accepted_channels' => [
                        'transactional' => ['email'],
                        'marketing' => [],
                    ],
                ],
            ]);

        $sourceRegistration->responses()->create([
            'question_key' => 'primary_question',
            'question_label' => 'What is your biggest question?',
            'question_type' => 'select',
            'answer_key' => 'affordability',
            'answer_label' => 'How much can I afford?',
            'answer_text' => null,
            'definition_version' => '2026_07',
            'sort_order' => 10,
        ]);

        app(ReplaceWebinarOccurrenceAction::class)->handle(
            source: $source,
            replacement: $replacement,
        );

        $replacementRegistration = WebinarRegistration::query()
            ->where('replacement_of_registration_id', $sourceRegistration->getKey())
            ->sole();

        $this->assertCount(1, $sourceRegistration->responses()->get());
        $this->assertCount(0, $replacementRegistration->responses()->get());
        $this->assertDatabaseCount('webinar_registration_responses', 1);
    }


    public function test_contact_webinar_history_displays_response_snapshots_and_other_text(): void
    {
        [$series, $webinar] = $this->occurrence('question-crm-history');
        $contact = Contact::factory()->create();
        $registration = WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create();

        $registration->responses()->create([
            'question_key' => 'primary_homebuying_question',
            'question_label' => 'What is your biggest question or concern about buying a home?',
            'question_type' => 'select',
            'answer_key' => 'other',
            'answer_label' => 'Other',
            'answer_text' => 'I want to understand how buying affects my monthly budget.',
            'definition_version' => '2026_07',
            'sort_order' => 10,
        ]);

        $panel = app(WebinarContactPanelProvider::class)->panels($contact)[0];
        $registrations = $panel->data['registrations'];
        $loadedRegistration = $registrations->sole();

        $this->assertTrue($loadedRegistration->relationLoaded('responses'));

        $html = view($panel->view, [
            ...$panel->data,
            'contactPanel' => $panel,
        ])->render();

        $this->assertStringContainsString('Registration Questions', $html);
        $this->assertStringContainsString(
            'What is your biggest question or concern about buying a home?',
            $html,
        );
        $this->assertStringContainsString('Other', $html);
        $this->assertStringContainsString(
            'I want to understand how buying affects my monthly budget.',
            $html,
        );
    }

    /**
     * @return array{0: WebinarSeries, 1: Webinar}
     */
    private function occurrence(string $slug): array
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => $slug,
            'title' => str($slug)->replace('-', ' ')->title()->toString(),
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addDay(),
        ]);

        return [$series, $webinar];
    }

    private function configureSeriesQuestions(WebinarSeries $series): void
    {
        Config::set(
            "webinars.register.{$series->slug}.content.registration.questions_section",
            [
                'enabled' => true,
                'title' => 'Help us tailor the class',
                'body' => 'Choose the answer that best fits your situation.',
            ],
        );
        Config::set(
            "webinars.register.{$series->slug}.content.registration.questions",
            [$this->questionDefinition(
                key: 'primary_question',
                label: 'What is your biggest question?',
            )],
        );
    }

    /** @return array<string, mixed> */
    private function questionDefinition(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'required' => true,
            'placeholder' => 'Choose one',
            'definition_version' => '2026_07',
            'options' => [
                [
                    'key' => 'affordability',
                    'label' => 'How much can I afford?',
                ],
                [
                    'key' => 'other',
                    'label' => 'Other',
                ],
            ],
            'other' => [
                'option_key' => 'other',
                'label' => 'Tell us more',
                'placeholder' => 'Share your question',
                'required' => true,
                'max_length' => 500,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'company_website' => '',
            'registration_form_ready' => 'ready',
            'registration_form_interacted' => 'human',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => null,
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_email_consent' => false,
            'marketing_sms_consent' => false,
        ], $overrides);
    }

    private function registrationUrl(
        WebinarSeries $series,
        Webinar $webinar,
    ): string {
        $showUrl = route('webinar.show', $series->slug);
        $scheme = parse_url($showUrl, PHP_URL_SCHEME);
        $host = parse_url($showUrl, PHP_URL_HOST);
        $port = parse_url($showUrl, PHP_URL_PORT);
        $origin = sprintf(
            '%s://%s%s',
            is_string($scheme) ? $scheme : 'http',
            is_string($host) ? $host : 'localhost',
            is_int($port) ? ':'.$port : '',
        );
        $path = URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinar->getKey(),
            ],
            absolute: false,
        );

        return $origin.$path;
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }
}