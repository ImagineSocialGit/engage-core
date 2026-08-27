<?php

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->timestamp('hidden_at')
                ->nullable()
                ->after('provider_archived_at');
            $table->string('hidden_reason', 64)
                ->nullable()
                ->after('hidden_at');

            $table->index(
                ['hidden_at', 'starts_at'],
                'webinars_hidden_starts_index',
            );
        });

        Schema::create('webinar_occurrence_suppressions', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(WebinarSeries::class)
                ->constrained('webinar_series')
                ->cascadeOnDelete();

            $table->string('platform', 64);
            $table->string('provider_event_type', 32);
            $table->string('external_id', 191);
            $table->string('external_uuid', 191)->nullable();
            $table->string('reason', 64)->default('operator_removed');
            $table->timestamp('suppressed_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'webinar_series_id',
                    'platform',
                    'provider_event_type',
                    'external_id',
                ],
                'webinar_occurrence_suppression_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_occurrence_suppressions');

        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropIndex('webinars_hidden_starts_index');
            $table->dropColumn([
                'hidden_at',
                'hidden_reason',
            ]);
        });
    }
};