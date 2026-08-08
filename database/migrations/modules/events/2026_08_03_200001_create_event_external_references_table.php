<?php

use App\Modules\Events\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_external_references', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Event::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider_key', 80)->index();
            $table->string('reference_type', 80)->index();
            $table->string('external_id', 191)->nullable();
            $table->text('url')->nullable();
            $table->string('label')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['provider_key', 'reference_type', 'external_id'],
                'event_external_references_provider_type_external_unique',
            );
            $table->index(
                ['event_id', 'reference_type'],
                'event_external_references_event_type_index',
            );
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreign(
                'primary_external_reference_id',
                'events_primary_external_reference_fk',
            )
                ->references('id')
                ->on('event_external_references')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign(
                'events_primary_external_reference_fk',
            );
        });

        Schema::dropIfExists('event_external_references');
    }
};