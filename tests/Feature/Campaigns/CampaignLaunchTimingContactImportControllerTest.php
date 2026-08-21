<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampaignLaunchTimingContactImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_campaign_launch_exposes_batch_input_and_requires_it_on_import(): void
    {
        Storage::fake('local');

        Config::set('contact_imports.profiles', [
            'timed_launch' => [
                'label' => 'Timed launch',
                'filename_contains' => ['timed launch'],
                'defaults' => [],
                'aliases' => [
                    'email' => ['Email'],
                ],
                'post_import' => [
                    'campaign_launch_timing' => [
                        'campaign_key' => 'candidate_campaign',
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent(
            'timed-launch.csv',
            "Email\nperson@example.test\n",
        );

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            ['csv' => $csv],
        );

        $preview->assertOk();
        $preview->assertViewHas('postImportInputs', function (array $inputs): bool {
            return ($inputs[0]['key'] ?? null) === 'campaign_launch_timing'
                && ($inputs[0]['inputs'][0]['key'] ?? null) === 'first_message_at';
        });
        $preview->assertSee(
            'post_import_inputs[campaign_launch_timing][first_message_at]',
            escape: false,
        );

        $csvPath = $preview->viewData('csvPath');

        $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                ],
                'treatments' => [],
            ],
        )->assertSessionHasErrors([
            'post_import_inputs.campaign_launch_timing.first_message_at',
        ]);
    }
}