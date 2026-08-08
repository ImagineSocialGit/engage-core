<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncContactStatusPresetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_sync_skips_unchanged_statuses_without_touching_updated_at(): void
    {
        config()->set('presets.packages.contact_status_idempotence_test', [
            'groups' => [
                'contact_statuses' => [
                    'default',
                ],
            ],
        ]);

        $resolved = app(PresetCompositionResolver::class)->resolve(
            'contact_status_idempotence_test',
            PresetDomain::ContactStatuses,
        );

        $action = app(SyncContactStatusPresetsAction::class);

        $expectedCount = count($resolved->definitionKeys);

        $firstResult = $action->handle($resolved);

        $this->assertSame($expectedCount, $firstResult['created']);
        $this->assertSame(0, $firstResult['updated']);
        $this->assertSame(0, $firstResult['skipped']);
        $this->assertEquals([], $firstResult['errors']);

        $sentinelUpdatedAt = '2000-01-01 00:00:00';

        DB::table('contact_statuses')
            ->whereIn('key', $resolved->definitionKeys)
            ->update([
                'updated_at' => $sentinelUpdatedAt,
            ]);

        $secondResult = $action->handle($resolved);

        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(0, $secondResult['updated']);
        $this->assertSame($expectedCount, $secondResult['skipped']);
        $this->assertEquals([], $secondResult['errors']);

        $this->assertSame(
            $expectedCount,
            DB::table('contact_statuses')
                ->whereIn('key', $resolved->definitionKeys)
                ->where('updated_at', $sentinelUpdatedAt)
                ->count(),
        );
    }
}