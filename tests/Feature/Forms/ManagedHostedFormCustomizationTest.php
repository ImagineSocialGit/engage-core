<?php

namespace Tests\Feature\Forms;

use App\Modules\Events\Models\Event;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Models\FormContextVersion;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\Forms\Services\HostedFormContextRegistry;
use App\Modules\Forms\Services\HostedFormContextService;
use App\Modules\Forms\Services\HostedFormPresenter;
use App\Modules\Forms\Services\ManagedHostedFormCustomization;
use App\Modules\Forms\Services\PublishedFormResolver;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ManagedHostedFormCustomizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms', 'events']);
        config()->set('human_verification.enabled', false);
        $this->app->register(FormsModuleServiceProvider::class, true);
        $this->app->register(IntegrationsModuleServiceProvider::class, true);
        $this->app->forgetInstance(HostedFormContextRegistry::class);
    }

    public function test_context_version_only_changes_that_event_and_preserves_original_definition(): void
    {
        $definition = $this->publishedForm();
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        $context = $this->reference($first);
        $otherContext = $this->reference($second);
        $editor = app(ManagedHostedFormCustomization::class);
        $forms = app(PublishedFormResolver::class);

        $before = $editor->editor('artist_updates', $context);
        $this->assertCount(2, $before['collect']);
        $this->assertCount(1, $before['permissions']);

        $selected = $this->selections($before);
        $selected['phone']['visible'] = false;
        $selected['email_marketing_consent']['visible'] = false;
        $published = $editor->publish('artist_updates', $context, $before['version_id'], $selected);

        $this->assertNotSame($before['version_id'], $published->versionId);
        $this->assertSame($before['version_id'], (int) $definition->fresh()->current_form_version_id);
        $this->assertSame($before['version_id'], $forms->require('artist_updates')->versionId);
        $this->assertSame($before['version_id'], $forms->requireForContext('artist_updates', $otherContext)->versionId);
        $this->assertSame($published->versionId, $forms->requireForContext('artist_updates', $context)->versionId);
        $this->assertSame(1, FormContextVersion::query()->count());

        $presenter = app(HostedFormPresenter::class);
        $contextBlocks = $presenter->present($published)['blocks'];
        $visibleFields = array_values(array_map(
            static fn (array $block): string => $block['field']['key'],
            array_values(array_filter($contextBlocks, static fn (array $block): bool => $block['type'] === 'field')),
        ));
        $this->assertSame(['email'], $visibleFields);

        $otherFields = $presenter->present($forms->requireForContext('artist_updates', $otherContext))['blocks'];
        $this->assertCount(3, array_filter($otherFields, static fn (array $block): bool => $block['type'] === 'field'));

        $signed = app(HostedFormContextService::class)->signedUrl('artist_updates', $context);
        $response = $this->get($signed)->assertOk();
        $rendered = $response->viewData('form');
        $this->assertSame($visibleFields, array_values(array_map(
            static fn (array $block): string => $block['field']['key'],
            array_values(array_filter($rendered['blocks'], static fn (array $block): bool => $block['type'] === 'field')),
        )));

        $token = collect($rendered['hidden_fields'])
            ->firstWhere('key', HostedFormContextService::POST_FIELD)['default'];
        $this->post(route('forms.public.store', ['formSlug' => 'artist-updates']), [
            'email' => 'first@example.com',
            'phone' => '6155551234',
            'email_marketing_consent' => '1',
            HostedFormContextService::POST_FIELD => $token,
        ])->assertRedirect();

        $submission = FormSubmission::query()->sole();
        $this->assertSame($published->versionId, (int) $submission->form_version_id);
        $this->assertSame(['email' => 'first@example.com'], $submission->payload);
    }

    public function test_publish_is_immutable_idempotent_and_rejects_stale_or_invalid_edits(): void
    {
        $this->publishedForm();
        $event = Event::factory()->create();
        $context = $this->reference($event);
        $editor = app(ManagedHostedFormCustomization::class);
        $state = $editor->editor('artist_updates', $context);
        $fields = $this->selections($state);
        $fields['phone']['required'] = true;

        $first = $editor->publish('artist_updates', $context, $state['version_id'], $fields);
        $this->assertTrue((bool) $first->field('phone')['required']);
        $this->assertSame(2, FormVersion::query()->count());
        $same = $editor->publish('artist_updates', $context, $first->versionId, $fields);
        $this->assertSame($first->versionId, $same->versionId);
        $this->assertSame(2, FormVersion::query()->count());

        try {
            $editor->publish('artist_updates', $context, $state['version_id'], $fields);
            $this->fail('Expected stale-version rejection.');
        } catch (DomainException) {
            $this->assertSame(2, FormVersion::query()->count());
        }

        $fields['email']['visible'] = false;

        try {
            $editor->publish('artist_updates', $context, $first->versionId, $fields);
            $this->fail('Expected invalid required-field hiding to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame(2, FormVersion::query()->count());
        }

        $original = FormVersion::query()->where('version', 1)->sole();
        $this->assertNull(data_get($original->schema, 'sections.0.fields.1.managed_visible'));
    }

    /** @return array<string, array{visible:bool,required:bool}> */
    private function selections(array $state): array
    {
        $selected = [];

        foreach (array_merge($state['collect'], $state['permissions']) as $field) {
            $selected[$field['key']] = [
                'visible' => $field['visible'],
                'required' => $field['required'],
            ];
        }

        return $selected;
    }

    private function reference(Event $event): HostedFormContextReference
    {
        return new HostedFormContextReference('event', (int) $event->getKey());
    }

    private function publishedForm(): FormDefinition
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Artist Updates',
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [
                        ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false],
                        ['key' => 'email_marketing_consent', 'label' => 'Email updates', 'type' => 'boolean', 'required' => false],
                    ],
                ]],
            ],
            'rules' => [
                'email' => ['required', 'email'],
                'phone' => ['nullable', 'string'],
                'email_marketing_consent' => ['nullable', 'boolean'],
            ],
            'layout' => [],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email', 'phone' => 'phone'],
                        'source' => 'core_hosted_forms',
                        'subsource' => 'artist_updates',
                    ],
                    'consents' => [[
                        'field' => 'email_marketing_consent',
                        'channel' => 'email',
                        'purpose' => 'marketing',
                    ]],
                ],
                'public' => ['hosted' => ['enabled' => true]],
            ],
        ]);
        $definition->forceFill(['current_form_version_id' => $version->getKey()])->save();

        return $definition->refresh();
    }
}