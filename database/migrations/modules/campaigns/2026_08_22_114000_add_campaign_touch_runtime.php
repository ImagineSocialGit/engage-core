<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->date('starts_on')
                ->nullable()
                ->after('repeat_years');
        });

        Schema::create('campaign_touch_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_touch_variant_id')
                ->constrained('campaign_touch_variants')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('occurrence_year');
            $table->timestamp('due_at');
            $table->unsignedBigInteger('scheduled_message_id')
                ->nullable()
                ->index();
            $table->string('status', 32);
            $table->string('reason', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'campaign_touch_variant_id',
                    'contact_id',
                    'occurrence_year',
                ],
                'campaign_touch_dispatch_occurrence_unique',
            );

            $table->index(
                ['status', 'due_at'],
                'campaign_touch_dispatch_status_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_touch_dispatches');

        Schema::table('campaign_touch_programs', function (Blueprint $table): void {
            $table->dropColumn('starts_on');
        });
    }
};