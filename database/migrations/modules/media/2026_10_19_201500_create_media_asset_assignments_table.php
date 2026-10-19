<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_asset_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_asset_id')
                ->constrained('media_assets')
                ->cascadeOnDelete();
            $table->morphs('subject');
            $table->string('role', 64);
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'role'],
                'media_asset_assignments_subject_role_unique',
            );
            $table->index(
                ['media_asset_id', 'role'],
                'media_asset_assignments_asset_role_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_assignments');
    }
};