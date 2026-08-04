<?php

use App\Modules\Events\Models\EventExternalReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignIdFor(
                EventExternalReference::class,
                'primary_external_reference_id',
            )
                ->nullable()
                ->constrained('event_external_references')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('primary_external_reference_id');
        });
    }
};