<?php

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_schedule_profile_chain_bindings', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(WebinarScheduleProfile::class)
                ->constrained(
                    table: 'webinar_schedule_profiles',
                    indexName: 'wsp_chain_bindings_profile_fk',
                )
                ->cascadeOnDelete();

            $table->string('key', 128);
            $table->string('message_area_key', 128);

            $table->foreignIdFor(MessageChain::class)
                ->constrained(
                    table: 'message_chains',
                    indexName: 'wsp_chain_bindings_chain_fk',
                )
                ->restrictOnDelete();

            $table->string('dispatch_key', 128)->index();
            $table->string('surface', 128)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['webinar_schedule_profile_id', 'message_area_key'],
                'wsp_chain_bindings_area_unique',
            );

            $table->index(
                ['webinar_schedule_profile_id', 'key', 'is_active'],
                'wsp_chain_bindings_key_active_idx',
            );

            $table->index(
                ['message_chain_id', 'is_active'],
                'wsp_chain_bindings_chain_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_schedule_profile_chain_bindings');
    }
};