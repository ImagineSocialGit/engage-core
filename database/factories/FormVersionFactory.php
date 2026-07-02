<?php

namespace Database\Factories;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 */
class FormVersionFactory extends Factory
{
    protected $model = FormVersion::class;

    public function definition(): array
    {
        return [
            'form_definition_id' => FormDefinition::factory(),
            'version' => 1,
            'status' => FormVersion::STATUS_DRAFT,
            'name' => 'Default form version',
            'description' => null,
            'schema' => [
                'sections' => [
                    [
                        'key' => 'main',
                        'label' => 'Main',
                        'fields' => [
                            [
                                'key' => 'notes',
                                'label' => 'Notes',
                                'type' => 'textarea',
                                'required' => false,
                            ],
                        ],
                    ],
                ],
            ],
            'rules' => [],
            'layout' => [],
            'settings' => [
                'submit_label' => 'Submit',
            ],
            'published_at' => null,
            'archived_at' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'meta' => null,
        ];
    }

    public function published(): self
    {
        return $this->state([
            'status' => FormVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'status' => FormVersion::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);
    }
}
