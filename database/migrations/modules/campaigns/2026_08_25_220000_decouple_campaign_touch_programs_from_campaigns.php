<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->makeProgramKeysGloballyUnique();

        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->dropForeign(['campaign_id']);
            $table->dropUnique('campaign_touch_program_key_unique');
            $table->dropIndex('campaign_touch_program_active_idx');
        });

        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
            $table->unique('key', 'campaign_touch_program_key_unique');
            $table->index('is_active', 'campaign_touch_program_active_idx');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('campaign_touch_programs')->whereNull('campaign_id')->exists()) {
            throw new \RuntimeException(
                'Cannot restore required Campaign ownership after standalone annual-touch programs have been created.'
            );
        }

        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->dropForeign(['campaign_id']);
            $table->dropUnique('campaign_touch_program_key_unique');
            $table->dropIndex('campaign_touch_program_active_idx');
        });

        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
            $table->unique(
                ['campaign_id', 'key'],
                'campaign_touch_program_key_unique',
            );
            $table->index(
                ['campaign_id', 'is_active'],
                'campaign_touch_program_active_idx',
            );
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();
        });
    }

    private function makeProgramKeysGloballyUnique(): void
    {
        $seen = [];

        foreach (DB::table('campaign_touch_programs')->orderBy('id')->get(['id', 'key']) as $row) {
            $key = trim((string) $row->key);
            $key = $key !== '' ? $key : 'annual_touch_'.$row->id;
            $candidate = $key;

            if (isset($seen[$candidate])) {
                $suffix = '_'.$row->id;
                $candidate = Str::limit($key, 120 - strlen($suffix), '').$suffix;

                while (isset($seen[$candidate])) {
                    $suffix .= '_x';
                    $candidate = Str::limit($key, 120 - strlen($suffix), '').$suffix;
                }
            }

            if ($candidate !== (string) $row->key) {
                DB::table('campaign_touch_programs')
                    ->where('id', $row->id)
                    ->update(['key' => $candidate]);
            }

            $seen[$candidate] = true;
        }
    }
};