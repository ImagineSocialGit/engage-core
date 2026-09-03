<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_is_a_registered_optional_universal_module(): void
    {
        config()->set('modules.enabled', []);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('media'));
        $this->assertFalse($modules->enabled('media'));
        $this->assertSame(['core'], $modules->dependencies('media'));
        $this->assertContains(
            MediaModuleServiceProvider::class,
            $modules->providers('media'),
        );
    }

    public function test_media_assets_have_stable_reusable_storage_identity(): void
    {
        foreach ([
            'uuid',
            'uploaded_by_type',
            'uploaded_by_id',
            'title',
            'kind',
            'disk',
            'path',
            'original_filename',
            'mime_type',
            'extension',
            'size_bytes',
            'checksum_sha256',
            'visibility',
            'source',
            'meta',
            'archived_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('media_assets', $column),
                "Expected media_assets.{$column} to exist.",
            );
        }
    }

    public function test_archive_scope_preserves_asset_record_instead_of_deleting_it(): void
    {
        $asset = MediaAsset::factory()->create();
        $asset->forceFill(['archived_at' => now()])->save();

        $this->assertSame(0, MediaAsset::query()->active()->count());
        $this->assertSame(1, MediaAsset::query()->archived()->count());
        $this->assertDatabaseHas('media_assets', ['id' => $asset->getKey()]);
    }
}