<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Jobs\ProcessContactImportBatchChunkJob;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportMarketingPermissionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['messaging']);
        Storage::fake('local');
        Queue::fake();
    }

    public function test_add_import_exposes_marketing_permission_choice_without_a_detected_profile(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'mode' => 'add',
                'csv' => UploadedFile::fake()->createWithContent(
                    'unrecognized-source.csv',
                    "Email\nperson@example.test\n",
                ),
            ],
        );

        $preview->assertOk();
        $preview->assertViewHas('postImportInputs', function (array $inputs): bool {
            $permission = collect($inputs)->firstWhere('key', 'marketing_permission');

            return is_array($permission)
                && collect($permission['inputs'] ?? [])->contains(
                    fn (array $input): bool => ($input['key'] ?? null) === 'permission_status',
                );
        });
    }

    public function test_missing_marketing_permission_input_defaults_to_no_imported_permission(): void
    {
        $user = User::factory()->create();
        $preview = $this->preview($user);

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => ['email' => 'Email'],
                'treatments' => [],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.import-batches.show', $batch));
        $this->assertArrayNotHasKey(
            'marketing_permission',
            data_get($batch->meta, 'post_import_config', []),
        );
    }

    public function test_no_or_unsure_imports_without_marketing_permission_processing(): void
    {
        $user = User::factory()->create();
        $preview = $this->preview($user);

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => ['email' => 'Email'],
                'treatments' => [],
                'post_import_inputs' => [
                    'marketing_permission' => [
                        'permission_status' => 'not_confirmed',
                    ],
                ],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.import-batches.show', $batch));
        $this->assertArrayNotHasKey(
            'marketing_permission',
            data_get($batch->meta, 'post_import_config', []),
        );
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class);
    }

    public function test_confirmed_permission_keeps_only_attested_selected_channels(): void
    {
        $user = User::factory()->create();
        $preview = $this->preview($user);

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => ['email' => 'Email'],
                'treatments' => [],
                'post_import_inputs' => [
                    'marketing_permission' => [
                        'permission_status' => 'confirmed',
                        'channels' => ['email'],
                        'attestation' => '1',
                    ],
                ],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $permission = data_get($batch->meta, 'post_import_config.marketing_permission');

        $response->assertRedirect(route('crm.contacts.import-batches.show', $batch));
        $this->assertEquals(['email'], $permission['channels'] ?? []);
        $this->assertSame('contact_import', $permission['scope'] ?? null);
        $this->assertSame('confirmed', $permission['operator_decision'] ?? null);
        $this->assertTrue((bool) ($permission['attested'] ?? false));
    }

    private function preview(User $user)
    {
        return $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'mode' => 'add',
                'csv' => UploadedFile::fake()->createWithContent(
                    'unrecognized-source.csv',
                    "Email\nperson@example.test\n",
                ),
            ],
        );
    }
}