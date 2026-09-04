<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageMediaAuthoringService;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UniversalMessageMediaAuthoringTest extends TestCase
{
    public function test_authoring_service_preserves_and_presents_current_archived_snapshot_without_live_lookup(): void
    {
        $library = new class implements MessageMediaLibrary
        {
            public int $snapshotCalls = 0;

            public function available(): bool
            {
                return true;
            }

            public function selectableAssets(): array
            {
                return [[
                    'uuid' => '11111111-1111-4111-8111-111111111111',
                    'title' => 'Selectable image',
                    'kind' => 'image',
                    'mime_type' => 'image/webp',
                    'public_url' => 'https://cdn.example.test/selectable.webp',
                ]];
            }

            public function snapshot(string $assetUuid, ?string $posterAssetUuid = null): array
            {
                $this->snapshotCalls++;

                return [
                    'asset_uuid' => $assetUuid,
                    'kind' => 'image',
                    'title' => 'Resolved asset',
                    'url' => 'https://cdn.example.test/resolved.webp',
                    'mime_type' => 'image/webp',
                    'tracking_key' => 'media_primary',
                ];
            }

            public function store(
                UploadedFile $file,
                ?string $title = null,
                ?string $posterAssetUuid = null,
                ?Model $uploadedBy = null,
            ): array {
                throw new \RuntimeException('Store should not be called in this test.');
            }
        };

        $current = [
            'asset_uuid' => '22222222-2222-4222-8222-222222222222',
            'kind' => 'video',
            'title' => 'Archived current video',
            'url' => 'https://cdn.example.test/current.mp4',
            'mime_type' => 'video/mp4',
            'tracking_key' => 'media_primary',
            'poster_asset_uuid' => '33333333-3333-4333-8333-333333333333',
            'poster_url' => 'https://cdn.example.test/current-poster.webp',
        ];

        $service = new MessageMediaAuthoringService($library);
        $presentation = $service->presentation([$current]);

        $this->assertTrue($presentation['available']);
        $this->assertContains($current['asset_uuid'], $presentation['asset_uuids']);
        $this->assertContains($current['poster_asset_uuid'], $presentation['image_asset_uuids']);

        $resolved = $service->resolve(
            submitted: true,
            assetUuid: $current['asset_uuid'],
            posterAssetUuid: $current['poster_asset_uuid'],
            currentMedia: $current,
        );

        $this->assertSame($current, $resolved);
        $this->assertSame(0, $library->snapshotCalls);

        $preservedPayload = $service->apply(
            payload: ['subject' => 'Hello', 'body' => 'World'],
            submitted: false,
            currentMedia: $current,
        );

        $this->assertSame($current, $preservedPayload['media']);
    }

    public function test_authoring_service_replaces_uploads_and_removes_media_through_one_contract(): void
    {
        $library = new class implements MessageMediaLibrary
        {
            public int $snapshotCalls = 0;

            public int $storeCalls = 0;

            public function available(): bool
            {
                return true;
            }

            public function selectableAssets(): array
            {
                return [];
            }

            public function snapshot(string $assetUuid, ?string $posterAssetUuid = null): array
            {
                $this->snapshotCalls++;

                return [
                    'asset_uuid' => $assetUuid,
                    'kind' => 'image',
                    'title' => 'Selected asset',
                    'url' => 'https://cdn.example.test/selected.webp',
                    'mime_type' => 'image/webp',
                    'tracking_key' => 'media_primary',
                ];
            }

            public function store(
                UploadedFile $file,
                ?string $title = null,
                ?string $posterAssetUuid = null,
                ?Model $uploadedBy = null,
            ): array {
                $this->storeCalls++;

                return [
                    'asset_uuid' => '55555555-5555-4555-8555-555555555555',
                    'kind' => 'image',
                    'title' => $title ?? 'Uploaded asset',
                    'url' => 'https://cdn.example.test/uploaded.webp',
                    'mime_type' => 'image/webp',
                    'tracking_key' => 'media_primary',
                ];
            }
        };

        $service = new MessageMediaAuthoringService($library);

        $selected = $service->resolve(
            submitted: true,
            assetUuid: '44444444-4444-4444-8444-444444444444',
        );

        $this->assertSame('44444444-4444-4444-8444-444444444444', $selected['asset_uuid']);
        $this->assertSame(1, $library->snapshotCalls);

        $uploaded = $service->resolve(
            submitted: true,
            upload: UploadedFile::fake()->create('photo.webp', 4, 'image/webp'),
            assetUuid: '44444444-4444-4444-8444-444444444444',
            title: 'Uploaded title',
        );

        $this->assertSame('55555555-5555-4555-8555-555555555555', $uploaded['asset_uuid']);
        $this->assertSame('Uploaded title', $uploaded['title']);
        $this->assertSame(1, $library->storeCalls);

        $this->assertNull($service->resolve(submitted: true));
    }

    public function test_every_email_authoring_surface_reaches_the_shared_media_authoring_contract(): void
    {
        $messagingSurfaces = [
            'resources/views/components/messaging/message-editor-carousel.blade.php',
            'resources/views/crm/messaging/message-templates/create.blade.php',
            'resources/views/components/messaging/route-message-template-picker.blade.php',
            'resources/views/crm/broadcasts/index.blade.php',
            'resources/views/crm/broadcasts/edit.blade.php',
            'resources/views/crm/campaigns/create.blade.php',
        ];

        foreach ($messagingSurfaces as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertIsString($source);
            $this->assertStringContainsString('<x-messaging.message-media-authoring', $source, $path);
        }

        $routePicker = file_get_contents(
            base_path('resources/views/components/messaging/route-message-template-picker.blade.php'),
        );
        $this->assertIsString($routePicker);
        $this->assertStringContainsString('new FormData(this.$refs.createForm)', $routePicker);

        foreach ([
            'resources/views/crm/campaigns/edit.blade.php',
            'resources/views/crm/webinars/message-chains/show.blade.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertIsString($source);
            $this->assertStringContainsString('<x-messaging.message-editor-carousel', $source, $path);
        }

        $schedulingView = file_get_contents(
            base_path('resources/views/crm/scheduling/communications.blade.php'),
        );
        $this->assertIsString($schedulingView);
        $this->assertStringContainsString('<x-ui.message-media-editor', $schedulingView);
        $this->assertStringNotContainsString('<x-messaging.message-media-authoring', $schedulingView);

        $schedulingContract = file_get_contents(
            base_path('app/Support/ModuleIntegrations/Scheduling/Contracts/AppointmentCommunications.php'),
        );
        $this->assertIsString($schedulingContract);
        $this->assertStringContainsString('public function authoringRules(): array;', $schedulingContract);
    }
}