<?php

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_series_message_chain_bindings', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(WebinarSeries::class)
                ->constrained(
                    table: 'webinar_series',
                    indexName: 'ws_chain_bindings_series_fk',
                )
                ->cascadeOnDelete();

            $table->string('key', 128);
            $table->string('message_area_key', 128);

            $table->foreignIdFor(MessageChain::class)
                ->constrained(
                    table: 'message_chains',
                    indexName: 'ws_chain_bindings_chain_fk',
                )
                ->restrictOnDelete();

            $table->string('dispatch_key', 128)->index();
            $table->string('surface', 128)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['webinar_series_id', 'message_area_key'],
                'ws_chain_bindings_area_unique',
            );

            $table->index(
                ['webinar_series_id', 'key', 'is_active'],
                'ws_chain_bindings_key_active_idx',
            );

            $table->index(
                ['message_chain_id', 'is_active'],
                'ws_chain_bindings_chain_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_series_message_chain_bindings');
    }
};