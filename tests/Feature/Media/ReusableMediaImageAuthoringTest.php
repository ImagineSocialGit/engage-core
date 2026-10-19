<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Media\Contracts\ReusableMediaImageAuthoring;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaAssetAssignment;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ReusableMediaImageAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/media-image-authoring'),
            'url' => 'https://cdn.example.test',
            'visibility' => 'public',
        ]);

        Storage::fake('public');
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test');

        $this->app->register(MediaModuleServiceProvider::class, force: true);
    }

    public function test_generated_svg_is_stored_once_and_exposed_through_the_public_image_seam(): void
    {
        $authoring = app(ReusableMediaImageAuthoring::class);
        $actor = User::factory()->create();

        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">
    <rect width="120" height="120" fill="#ffffff"/>
    <rect x="12" y="12" width="96" height="96" fill="#111111"/>
</svg>
SVG;

        $first = $authoring->storeGeneratedSvg(
            svg: $svg,
            title: 'Event signup QR',
            source: 'event_signup_kit',
            createdBy: $actor,
        );

        $second = $authoring->storeGeneratedSvg(
            svg: $svg,
            title: 'Same QR again',
            source: 'event_signup_kit',
            createdBy: $actor,
        );

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame('image/svg+xml', $first->mimeType);
        $this->assertSame(1, MediaAsset::query()->count());

        $asset = MediaAsset::query()->firstOrFail();

        $this->assertSame('event_signup_kit', $asset->source);
        $this->assertSame($actor->getMorphClass(), $asset->uploaded_by_type);
        $this->assertSame($actor->getKey(), $asset->uploaded_by_id);
        $this->assertSame(MediaAsset::INGESTION_READY, $asset->ingestionStatus());
        $this->assertSame(hash('sha256', $svg), $asset->checksum_sha256);
        Storage::disk('public')->assertExists((string) $asset->path);
        $this->assertSame($svg, Storage::disk('public')->get((string) $asset->path));
    }

    public function test_subject_role_assignment_switches_current_image_without_deleting_prior_assets(): void
    {
        $authoring = app(ReusableMediaImageAuthoring::class);
        $subject = User::factory()->create();

        $first = $authoring->storeGeneratedSvg(
            svg: $this->svg('#111111'),
            title: 'First QR',
            source: 'event_signup_kit',
        );
        $second = $authoring->storeGeneratedSvg(
            svg: $this->svg('#222222'),
            title: 'Second QR',
            source: 'event_signup_kit',
        );

        $assignedFirst = $authoring->assign(
            subject: $subject,
            role: 'event_signup_qr',
            assetUuid: $first->uuid,
        );

        $this->assertSame($first->uuid, $assignedFirst->uuid);
        $this->assertSame($first->uuid, $authoring->assigned(
            $subject,
            'event_signup_qr',
        )?->uuid);

        $assignedSecond = $authoring->assign(
            subject: $subject,
            role: 'event_signup_qr',
            assetUuid: $second->uuid,
        );

        $this->assertSame($second->uuid, $assignedSecond->uuid);
        $this->assertSame($second->uuid, $authoring->assigned(
            $subject,
            'event_signup_qr',
        )?->uuid);
        $this->assertSame(1, MediaAssetAssignment::query()->count());
        $this->assertSame(2, MediaAsset::query()->count());
        $this->assertDatabaseHas('media_assets', ['uuid' => $first->uuid]);
        $this->assertDatabaseHas('media_assets', ['uuid' => $second->uuid]);

        $authoring->clearAssignment($subject, 'event_signup_qr');

        $this->assertNull($authoring->assigned($subject, 'event_signup_qr'));
        $this->assertSame(0, MediaAssetAssignment::query()->count());
        $this->assertSame(2, MediaAsset::query()->count());
    }

    public function test_assignment_rejects_unavailable_images_and_invalid_roles(): void
    {
        $authoring = app(ReusableMediaImageAuthoring::class);
        $subject = User::factory()->create();

        try {
            $authoring->assign(
                subject: $subject,
                role: 'Event Signup QR',
                assetUuid: '00000000-0000-4000-8000-000000000000',
            );
            $this->fail('Invalid role was accepted.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(RuntimeException::class);

        $authoring->assign(
            subject: $subject,
            role: 'event_signup_qr',
            assetUuid: '00000000-0000-4000-8000-000000000000',
        );
    }

    private function svg(string $fill): string
    {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
    <rect width="100" height="100" fill="{$fill}"/>
</svg>
SVG;
    }
}