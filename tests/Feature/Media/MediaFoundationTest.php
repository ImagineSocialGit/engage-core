<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Database\QueryException;
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

    public function test_media_checksum_is_a_unique_exact_content_identity(): void
    {
        $checksum = hash('sha256', 'identical-media-content');

        MediaAsset::factory()->create([
            'checksum_sha256' => $checksum,
        ]);

        $this->expectException(QueryException::class);

        MediaAsset::factory()->create([
            'checksum_sha256' => $checksum,
        ]);
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