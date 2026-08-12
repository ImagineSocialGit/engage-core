<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Services\PublishedFormResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishedFormRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_resolves_exact_current_published_version_into_transport_neutral_contract(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => true,
        ]);

        FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Old Artist Updates',
            'schema' => $this->schema('old_email'),
        ]);

        $current = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 2,
            'name' => 'Current Artist Updates',
            'description' => 'Current published snapshot.',
            'schema' => $this->schema('email'),
            'rules' => ['email' => ['email']],
            'layout' => ['variant' => 'compact'],
            'settings' => ['success_message_key' => 'artist_updates_saved'],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $current->getKey(),
        ])->save();

        $published = app(PublishedFormResolver::class)->require(
            key: 'artist_updates',
            publicOnly: true,
        );

        $this->assertInstanceOf(PublishedForm::class, $published);
        $this->assertSame((int) $definition->getKey(), $published->definitionId);
        $this->assertSame((int) $current->getKey(), $published->versionId);
        $this->assertSame(2, $published->versionNumber);
        $this->assertSame('Current Artist Updates', $published->name);
        $this->assertSame(['email'], $published->fieldKeys());
        $this->assertSame('email', $published->field('email')['type']);
        $this->assertSame('contact', $published->field('email')['section_key']);
        $this->assertSame(
            'artist_updates_saved',
            $published->settings['success_message_key'],
        );
    }

    public function test_public_runtime_does_not_resolve_private_or_inactive_forms(): void
    {
        $private = $this->publishedDefinition(
            key: 'private_intake',
            isPublic: false,
        );
        $inactive = $this->publishedDefinition(
            key: 'inactive_intake',
            isPublic: true,
        );
        $inactive->forceFill([
            'status' => FormDefinition::STATUS_ARCHIVED,
        ])->save();

        $resolver = app(PublishedFormResolver::class);

        $this->assertNull($resolver->find('private_intake', publicOnly: true));
        $this->assertNotNull($resolver->find('private_intake'));
        $this->assertNull($resolver->find('inactive_intake'));
        $this->assertNull($resolver->find('not_a_real_form'));
    }

    public function test_active_definition_with_unpublished_current_version_fails_closed(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'broken_form',
            'is_public' => true,
        ]);
        $draft = FormVersion::factory()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => FormVersion::STATUS_DRAFT,
            'published_at' => null,
            'schema' => $this->schema('email'),
        ]);

        $definition->forceFill([
            'current_form_version_id' => $draft->getKey(),
        ])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'does not point at a current published FormVersion',
        );

        app(PublishedFormResolver::class)->require('broken_form');
    }

    public function test_invalid_published_schema_fails_closed_at_runtime(): void
    {
        $definition = $this->publishedDefinition(
            key: 'invalid_schema',
            isPublic: true,
        );
        $version = $definition->currentVersion()->sole();

        FormVersion::query()
            ->whereKey($version->getKey())
            ->update([
                'schema' => json_encode([
                    'sections' => [[
                        'key' => 'contact',
                        'fields' => [[
                            'key' => 'email',
                            'label' => 'Email',
                            'type' => 'unsupported',
                        ]],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);

        $definition->unsetRelation('currentVersion');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('has an invalid schema');

        app(PublishedFormResolver::class)->require('invalid_schema');
    }

    private function publishedDefinition(
        string $key,
        bool $isPublic,
    ): FormDefinition {
        $definition = FormDefinition::factory()->active()->create([
            'key' => $key,
            'is_public' => $isPublic,
        ]);

        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'schema' => $this->schema('email'),
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $fieldKey): array
    {
        return [
            'sections' => [[
                'key' => 'contact',
                'label' => 'Contact',
                'fields' => [[
                    'key' => $fieldKey,
                    'label' => 'Email',
                    'type' => 'email',
                    'required' => true,
                ]],
            ]],
        ];
    }
}