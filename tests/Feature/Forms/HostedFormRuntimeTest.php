<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Services\FormVisibilityEvaluator;
use App\Modules\Forms\Services\PublishedFormResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class HostedFormRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
    }

    public function test_hosted_submit_route_uses_forms_public_human_verification_boundary(): void
    {
        $route = RouteFacade::getRoutes()->getByName('forms.public.store');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertContains('module:forms', $route->gatherMiddleware());
        $this->assertContains('public-human:forms', $route->gatherMiddleware());
    }

    public function test_hosted_public_form_renders_on_forms_subdomain(): void
    {
        $this->publishedHostedForm();

        $this->get($this->formUrl())
            ->assertOk()
            ->assertViewIs('forms.show')
            ->assertSee('data-public-surface', false)
            ->assertSee('Conditional Intake')
            ->assertSee('Current employment type')
            ->assertSee('data-form-block-key="employment_gap_note"', false)
            ->assertSee('data-hosted-form-conditions', false)
            ->assertSee('Submit intake')
            ->assertDontSee('name="website"', false);
    }

    public function test_hidden_conditional_required_field_is_ignored_and_submission_maps_contact(): void
    {
        $this->publishedHostedForm();

        $response = $this->post($this->formUrl(), [
            'employment_type' => 'self_employed',
            'adverse_credit_events' => ['none'],
            'first_name' => 'Taylor',
            'email' => 'taylor@example.com',
        ]);

        $response
            ->assertRedirect($this->formUrl())
            ->assertSessionHas('forms.hosted.success', 'conditional_intake')
            ->assertSessionHas(
                'public_surfaces.tracking.event',
                'form_submission_completed',
            );

        $contact = Contact::query()->where('email', 'taylor@example.com')->firstOrFail();
        $submission = FormSubmission::query()->sole();

        $this->assertSame($contact->getKey(), $submission->contact_id);
        $this->assertSame('self_employed', $submission->payload['employment_type']);
        $this->assertArrayNotHasKey('w2_employment_gap', $submission->payload);
        $this->assertSame(['none'], $submission->payload['adverse_credit_events']);
    }

    public function test_visible_conditional_required_field_is_enforced(): void
    {
        $this->publishedHostedForm();

        $this->from($this->formUrl())
            ->post($this->formUrl(), [
                'employment_type' => 'w2_employee',
                'adverse_credit_events' => ['none'],
                'first_name' => 'Taylor',
                'email' => 'taylor@example.com',
            ])
            ->assertRedirect($this->formUrl())
            ->assertSessionHasErrors('w2_employment_gap')
            ->assertSessionMissing('public_surfaces.tracking.event');

        $this->assertSame(0, FormSubmission::query()->count());
        $this->assertSame(0, Contact::query()->where('email', 'taylor@example.com')->count());
    }

    public function test_stale_values_from_now_hidden_fields_are_not_persisted(): void
    {
        $this->publishedHostedForm();

        $this->post($this->formUrl(), [
            'employment_type' => 'self_employed',
            'w2_employment_gap' => 'yes',
            'adverse_credit_events' => ['none'],
            'first_name' => 'Taylor',
            'email' => 'taylor@example.com',
        ])->assertRedirect($this->formUrl());

        $submission = FormSubmission::query()->sole();

        $this->assertArrayNotHasKey('w2_employment_gap', $submission->payload);
        $this->assertEqualsCanonicalizing(
            ['employment_type', 'adverse_credit_events', 'first_name', 'email'],
            array_keys($submission->payload),
        );
    }

    public function test_exclusive_checkbox_option_cannot_be_combined_with_other_values(): void
    {
        $this->publishedHostedForm();

        $this->from($this->formUrl())
            ->post($this->formUrl(), [
                'employment_type' => 'self_employed',
                'adverse_credit_events' => ['bankruptcy', 'none'],
                'first_name' => 'Taylor',
                'email' => 'taylor@example.com',
            ])
            ->assertRedirect($this->formUrl())
            ->assertSessionHasErrors('adverse_credit_events');

        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_nested_visibility_uses_only_currently_visible_controller_values(): void
    {
        $this->publishedHostedForm();

        $form = app(PublishedFormResolver::class)->require(
            key: 'conditional_intake',
            publicOnly: true,
        );
        $visible = app(FormVisibilityEvaluator::class)->visibleFieldKeys($form, [
            'employment_type' => 'self_employed',
            'w2_employment_gap' => 'yes',
            'adverse_credit_events' => ['bankruptcy'],
        ]);

        $this->assertFalse(in_array('w2_employment_gap', $visible, true));
        $this->assertTrue(in_array('adverse_event_age', $visible, true));
    }

    public function test_non_hosted_form_is_not_publicly_rendered(): void
    {
        $this->publishedHostedForm(hosted: false);

        $this->get($this->formUrl())->assertNotFound();
    }

    private function formUrl(): string
    {
        return 'http://forms.'.config('app.root_domain').'/conditional-intake';
    }

    private function publishedHostedForm(bool $hosted = true): FormDefinition
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'conditional_intake',
            'name' => 'Conditional Intake',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Conditional Intake',
            'description' => 'A hosted form runtime acceptance fixture.',
            'schema' => [
                'sections' => [[
                    'key' => 'intake',
                    'label' => 'Intake',
                    'fields' => [
                        [
                            'key' => 'employment_type',
                            'label' => 'Current employment type',
                            'type' => 'radio',
                            'required' => true,
                            'options' => [
                                ['value' => 'w2_employee', 'label' => 'W-2 employee'],
                                ['value' => 'self_employed', 'label' => 'Self-employed'],
                            ],
                        ],
                        [
                            'key' => 'w2_employment_gap',
                            'label' => 'Any employment gaps longer than 60 days?',
                            'type' => 'radio',
                            'required' => true,
                            'options' => [
                                ['value' => 'yes', 'label' => 'Yes'],
                                ['value' => 'no', 'label' => 'No'],
                            ],
                        ],
                        [
                            'key' => 'adverse_credit_events',
                            'label' => 'Have you ever had any of the following?',
                            'type' => 'checkboxes',
                            'required' => true,
                            'options' => [
                                ['value' => 'bankruptcy', 'label' => 'Bankruptcy'],
                                ['value' => 'foreclosure', 'label' => 'Foreclosure'],
                                ['value' => 'none', 'label' => 'None'],
                            ],
                            'exclusive_options' => ['none'],
                        ],
                        [
                            'key' => 'adverse_event_age',
                            'label' => 'How long ago was it?',
                            'type' => 'radio',
                            'required' => false,
                            'options' => [
                                ['value' => 'under_1_year', 'label' => 'Less than 1 year ago'],
                                ['value' => 'over_1_year', 'label' => 'More than 1 year ago'],
                            ],
                        ],
                        [
                            'key' => 'first_name',
                            'label' => 'First Name',
                            'type' => 'text',
                            'required' => true,
                        ],
                        [
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'email',
                            'required' => true,
                        ],
                    ],
                ]],
            ],
            'rules' => [
                'w2_employment_gap' => ['required'],
                'email' => ['required', 'email', 'max:255'],
                'first_name' => ['required', 'string', 'max:255'],
            ],
            'layout' => [
                'blocks' => [
                        [
                            'type' => 'content',
                            'key' => 'intro_copy',
                            'variant' => 'body',
                            'text' => 'This is an introduction.',
                        ],
                        ['type' => 'field', 'field' => 'employment_type'],
                        ['type' => 'field', 'field' => 'w2_employment_gap'],
                        [
                            'type' => 'content',
                            'key' => 'employment_gap_note',
                            'variant' => 'note',
                            'text' => 'Employment gaps can require a closer look.',
                        ],
                        ['type' => 'field', 'field' => 'adverse_credit_events'],
                        ['type' => 'field', 'field' => 'adverse_event_age'],
                        ['type' => 'field', 'field' => 'first_name'],
                        ['type' => 'field', 'field' => 'email'],
                ],
                'conditions' => [
                        [
                            'targets' => ['w2_employment_gap'],
                            'match' => 'all',
                            'when' => [[
                                'field' => 'employment_type',
                                'operator' => 'equals',
                                'value' => 'w2_employee',
                            ]],
                        ],
                        [
                            'targets' => ['employment_gap_note'],
                            'match' => 'all',
                            'when' => [[
                                'field' => 'w2_employment_gap',
                                'operator' => 'equals',
                                'value' => 'yes',
                            ]],
                        ],
                        [
                            'targets' => ['adverse_event_age'],
                            'match' => 'any',
                            'when' => [
                                [
                                    'field' => 'adverse_credit_events',
                                    'operator' => 'contains',
                                    'value' => 'bankruptcy',
                                ],
                                [
                                    'field' => 'adverse_credit_events',
                                    'operator' => 'contains',
                                    'value' => 'foreclosure',
                                ],
                            ],
                        ],
                ],
            ],
            'settings' => [
                'public' => [
                    'hosted' => [
                        'enabled' => $hosted,
                        'submit_label' => 'Submit intake',
                        'success' => [
                            'heading' => 'Thanks',
                            'message' => 'We received your answers.',
                        ],
                    ],
                ],
                'submission' => [
                    'contact' => [
                        'fields' => [
                            'email' => 'email',
                            'first_name' => 'first_name',
                        ],
                        'source' => 'forms',
                        'subsource' => 'conditional_intake',
                    ],
                ],
            ],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }
}