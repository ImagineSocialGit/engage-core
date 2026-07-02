<?php

namespace Database\Factories;

use App\Modules\Forms\Models\FormDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FormDefinition>
 */
class FormDefinitionFactory extends Factory
{
    protected $model = FormDefinition::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'key' => Str::slug($name).'_'.fake()->unique()->numberBetween(1000, 9999),
            'name' => Str::title($name),
            'description' => fake()->optional()->sentence(),
            'status' => FormDefinition::STATUS_DRAFT,
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => false,
            'current_form_version_id' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'status' => FormDefinition::STATUS_ACTIVE,
        ]);
    }

    public function public(): self
    {
        return $this->state([
            'is_public' => true,
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'status' => FormDefinition::STATUS_ARCHIVED,
        ]);
    }
}
