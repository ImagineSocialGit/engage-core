<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarPostRegistrationQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();

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
        Config::set('webinars.register.content.registration.fields.last_name.enabled', false);
        Config::set('webinars.register.content.registration.questions', [
            [
                'key' => 'primary_question',
                'label' => 'What is the one thing you want explained?',
                'type' => 'textarea',
                'placement' => 'post_registration',
                'required' => true,
                'max_length' => 500,
                'definition_version' => 'test_post_v1',
            ],
            [
                'key' => 'optional_question',
                'label' => 'Anything else?',
                'type' => 'textarea',
                'placement' => 'post_registration',
                'required' => false,
                'max_length' => 500,
                'definition_version' => 'test_post_v1',
            ],
        ]);
    }

    public function test_initial_registration_does_not_require_post_registration_questions_and_redirects_to_the_question_page(): void
    {
        [$series, $webinar] = $this->webinarFixture('post-question-redirect');

        $response = $this->post(
            $this->registrationPath($series, $webinar),
            $this->registrationPayload(),
        );

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('registration_questions');

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/thank-you/', $location);
        $this->assertStringContainsString('/questions?', $location);
        $this->assertDatabaseCount('webinar_registrations', 1);
        $this->assertDatabaseCount('webinar_registration_responses', 0);
    }

    public function test_post_registration_question_page_validates_configured_questions_and_persists_answers_without_affecting_registration(): void
    {
        [$series, $webinar] = $this->webinarFixture('post-question-persistence');

        $this->post(
            $this->registrationPath($series, $webinar),
            $this->registrationPayload(),
        )->assertRedirect();

        $registration = WebinarRegistration::query()->firstOrFail();
        $showPath = $this->postQuestionPath(
            'webinar.registration.questions.show',
            $series,
            $registration,
        );
        $storePath = $this->postQuestionPath(
            'webinar.registration.questions.store',
            $series,
            $registration,
        );

        $this->get($showPath)
            ->assertOk()
            ->assertSee('What is the one thing you want explained?')
            ->assertSee('Anything else?')
            ->assertSee('name="registration_questions[primary_question][answer]"', false);

        $this->from($showPath)
            ->post($storePath, [])
            ->assertRedirect($showPath)
            ->assertSessionHasErrors('registration_questions.primary_question.answer');

        $response = $this->post($storePath, [
            'registration_questions' => [
                'primary_question' => [
                    'answer' => 'Please explain the timeline from approval through closing.',
                ],
                'optional_question' => [
                    'answer' => 'Please also cover common documentation mistakes.',
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString(
            '/thank-you/'.$registration->getKey(),
            (string) $response->headers->get('Location'),
        );
        $this->assertStringNotContainsString(
            '/questions',
            (string) $response->headers->get('Location'),
        );

        $this->assertDatabaseHas('webinar_registration_responses', [
            'webinar_registration_id' => $registration->getKey(),
            'question_key' => 'primary_question',
            'question_type' => 'textarea',
            'answer_key' => 'text',
            'answer_text' => 'Please explain the timeline from approval through closing.',
            'definition_version' => 'test_post_v1',
        ]);
        $this->assertDatabaseHas('webinar_registration_responses', [
            'webinar_registration_id' => $registration->getKey(),
            'question_key' => 'optional_question',
            'answer_text' => 'Please also cover common documentation mistakes.',
        ]);
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => $registration->getKey(),
        ]);
    }

    public function test_inline_presentation_renders_the_same_registration_form_in_the_hero_without_modal_chrome(): void
    {
        Config::set('webinars.register.content.registration.presentation', 'inline');
        Config::set('webinars.register.content.registration.questions', []);

        [$series] = $this->webinarFixture('inline-presentation');

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee('id="webinar-registration-form"', false);
        $response->assertDontSee('aria-modal="true"', false);
        $response->assertDontSee('name="last_name"', false);
        $response->assertSee('name="first_name"', false);
        $response->assertSee('name="email"', false);
    }

    /**
     * @return array{0: WebinarSeries, 1: Webinar}
     */
    private function webinarFixture(string $slug): array
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => $slug,
            'title' => 'Configured Test Class',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => $slug.'-occurrence',
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        return [$series, $webinar];
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return [
            'company_website' => '',
            'registration_form_ready' => 'ready',
            'registration_form_interacted' => 'human',
            'first_name' => 'Taylor',
            'email' => 'taylor@example.com',
            'phone' => null,
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_email_consent' => false,
            'marketing_sms_consent' => false,
        ];
    }

    private function registrationPath(
        WebinarSeries $series,
        Webinar $webinar,
    ): string {
        return URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinar->getKey(),
            ],
            absolute: false,
        );
    }

    private function postQuestionPath(
        string $routeName,
        WebinarSeries $series,
        WebinarRegistration $registration,
    ): string {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addHour(),
            [
                'seriesSlug' => $series->slug,
                'registration' => $registration,
            ],
            absolute: false,
        );
    }
}