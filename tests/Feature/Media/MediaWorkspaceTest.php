<?php

namespace Tests\Feature\Media;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test');
        Storage::fake('public');

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_silent_media_library_is_available_from_shared_settings_without_primary_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.settings.index'))
            ->assertOk()
            ->assertSee('Media library')
            ->assertSee(route('crm.media.index'), false);

        $navigationRoutes = collect(app(ModuleManager::class)->navigationItems())
            ->pluck('route')
            ->all();

        $this->assertNotContains('crm.media.index', $navigationRoutes);
    }

    public function test_authenticated_operator_can_upload_a_reusable_video_asset(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create(
            'fan-welcome.mp4',
            1024,
            'video/mp4',
        );

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Fan welcome greeting',
                'file' => $file,
            ])
            ->assertRedirect(route('crm.media.index'));

        $asset = MediaAsset::query()->sole();

        $this->assertSame(MediaAsset::KIND_VIDEO, $asset->kind);
        $this->assertSame('video/mp4', $asset->mime_type);
        $this->assertSame('public', $asset->disk);
        $this->assertSame($user->getMorphClass(), $asset->uploaded_by_type);
        $this->assertSame($user->getKey(), $asset->uploaded_by_id);
        $this->assertNotSame('', $asset->checksum_sha256);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_exact_duplicate_upload_reuses_existing_asset_and_storage_object(): void
    {
        $user = User::factory()->create();
        $contents = 'same reusable media bytes';

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Original reusable file',
                'file' => UploadedFile::fake()->createWithContent(
                    'original.txt',
                    $contents,
                ),
            ])
            ->assertRedirect(route('crm.media.index'))
            ->assertSessionHas('media_upload_status', 'created');

        $original = MediaAsset::query()->sole();

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Different filename and title',
                'file' => UploadedFile::fake()->createWithContent(
                    'renamed-copy.txt',
                    $contents,
                ),
            ])
            ->assertRedirect(route('crm.media.index'))
            ->assertSessionHas('media_upload_status', 'reused');

        $reused = MediaAsset::query()->sole();

        $this->assertSame($original->getKey(), $reused->getKey());
        $this->assertSame('Original reusable file', $reused->title);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        Storage::disk('public')->assertExists($original->path);
    }

    public function test_exact_duplicate_upload_restores_archived_asset_instead_of_creating_another(): void
    {
        $user = User::factory()->create();
        $contents = 'archived reusable media bytes';

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Archived reusable file',
                'file' => UploadedFile::fake()->createWithContent(
                    'archive-me.txt',
                    $contents,
                ),
            ])
            ->assertRedirect(route('crm.media.index'));

        $original = MediaAsset::query()->sole();
        $original->forceFill(['archived_at' => now()])->save();

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'file' => UploadedFile::fake()->createWithContent(
                    'same-content-new-name.txt',
                    $contents,
                ),
            ])
            ->assertRedirect(route('crm.media.index'))
            ->assertSessionHas('media_upload_status', 'reused');

        $restored = MediaAsset::query()->sole();

        $this->assertSame($original->getKey(), $restored->getKey());
        $this->assertNull($restored->archived_at);
        $this->assertCount(1, Storage::disk('public')->allFiles());
        Storage::disk('public')->assertExists($original->path);
    }

    public function test_media_workspace_lists_active_assets_and_can_archive_and_restore_them(): void
    {
        $user = User::factory()->create();
        $active = MediaAsset::factory()->create([
            'disk' => 'public',
        ]);
        Storage::disk('public')->put($active->path, 'fixture');

        $this->actingAs($user)
            ->get(route('crm.media.index'))
            ->assertOk()
            ->assertSee('data-media-library', false)
            ->assertSee('data-media-asset-id="'.$active->getKey().'"', false);

        $this->actingAs($user)
            ->patch(route('crm.media.archive', $active))
            ->assertRedirect(route('crm.media.index'));

        $active->refresh();
        $this->assertNotNull($active->archived_at);
        Storage::disk('public')->assertExists($active->path);

        $this->actingAs($user)
            ->patch(route('crm.media.restore', $active))
            ->assertRedirect(route('crm.media.index', ['archived' => 1]));

        $this->assertNull($active->refresh()->archived_at);
    }
}