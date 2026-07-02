<?php

namespace Database\Factories;

use App\Modules\Documents\Models\DocumentRequirementDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentRequirementDefinition>
 */
class DocumentRequirementDefinitionFactory extends Factory
{
    protected $model = DocumentRequirementDefinition::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'key' => Str::slug($name).'_'.fake()->unique()->numberBetween(1000, 9999),
            'name' => Str::title($name),
            'description' => fake()->optional()->sentence(),
            'instructions' => fake()->optional()->sentence(),
            'status' => DocumentRequirementDefinition::STATUS_DRAFT,
            'category' => DocumentRequirementDefinition::CATEGORY_GENERAL,
            'is_required_by_default' => false,
            'allows_multiple_uploads' => false,
            'requires_review' => true,
            'accepted_mime_types' => null,
            'max_file_size_kb' => null,
            'expires_after_days' => null,
            'sort_order' => 0,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'settings' => null,
            'meta' => null,
        ];
    }

    public function active(): self
    {
        return $this->state([
            'status' => DocumentRequirementDefinition::STATUS_ACTIVE,
        ]);
    }

    public function requiredByDefault(): self
    {
        return $this->state([
            'is_required_by_default' => true,
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'status' => DocumentRequirementDefinition::STATUS_ARCHIVED,
        ]);
    }
}
