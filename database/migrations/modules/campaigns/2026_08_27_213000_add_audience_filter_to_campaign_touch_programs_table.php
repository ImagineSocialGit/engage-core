<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->json('audience_filter')
                ->nullable()
                ->after('audience_key');
        });

        DB::table('campaign_touch_programs')
            ->orderBy('id')
            ->get(['id', 'audience_type', 'audience_key'])
            ->each(function (object $program): void {
                if ((string) $program->audience_type !== 'contact_status') {
                    return;
                }

                $key = trim((string) $program->audience_key);

                if ($key === '') {
                    return;
                }

                DB::table('campaign_touch_programs')
                    ->where('id', $program->id)
                    ->update([
                        'audience_filter' => json_encode([
                            'mode' => 'criteria',
                            'criteria' => [
                                'status' => [$key],
                            ],
                            'contact_ids' => [],
                            'exclude' => [
                                'criteria' => [],
                                'contact_ids' => [],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->dropColumn('audience_filter');
        });
    }
};