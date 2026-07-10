<?php

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_schedule_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default(WebinarScheduleProfile::STATUS_ACTIVE)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->string('source')->nullable()->index();
            $table->string('source_config_path')->nullable();
            $table->unsignedInteger('source_version')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_customized', 'is_active'], 'wsp_customized_active_idx');
        });

        Schema::create('webinar_schedule_profile_items', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(WebinarScheduleProfile::class)
                ->constrained(
                    table: 'webinar_schedule_profiles',
                    indexName: 'wsp_items_profile_fk',
                )
                ->cascadeOnDelete();

            $table->string('key');
            $table->string('label')->nullable();
            $table->string('context_key')->index();

            $table->string('channel')->index();
            $table->string('purpose')->index();
            $table->string('scope')->index();
            $table->string('surface')->nullable()->index();
            $table->string('message_type')->index();
            $table->string('dispatch_key')->index();
            $table->string('message_template_key')->index();
            $table->string('source_config_path')->nullable()->index();

            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->string('timing')->default('immediate');
            $table->json('schedule')->nullable();
            $table->json('conditions')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['webinar_schedule_profile_id', 'key'],
                'wsp_items_key_unique',
            );

            $table->index(
                ['webinar_schedule_profile_id', 'channel', 'purpose', 'scope'],
                'wsp_items_route_idx',
            );

            $table->index(
                ['webinar_schedule_profile_id', 'message_type', 'dispatch_key'],
                'wsp_items_message_idx',
            );

            $table->index(
                ['webinar_schedule_profile_id', 'message_template_key'],
                'wsp_items_template_idx',
            );

            $table->index(
                ['webinar_schedule_profile_id', 'is_customized', 'is_active'],
                'wsp_items_customized_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_schedule_profile_items');
        Schema::dropIfExists('webinar_schedule_profiles');
    }
};
