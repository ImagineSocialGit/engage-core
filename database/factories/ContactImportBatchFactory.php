<?php

namespace Database\Factories;

use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactImportBatch>
 */
class ContactImportBatchFactory extends Factory
{
    protected $model = ContactImportBatch::class;

    public function definition(): array
    {
        return [
            'name' => 'Import '.fake()->dateTimeBetween('-30 days')->format('Y-m-d H:i:s'),
            'source' => 'csv',
            'original_filename' => fake()->slug().'.csv',
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'imported_at' => now(),
            'contact_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [],
        ];
    }
}
