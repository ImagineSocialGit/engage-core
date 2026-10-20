<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_context_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_definition_id')
                ->constrained('form_definitions')
                ->cascadeOnDelete();
            $table->string('context_key', 80);
            $table->string('context_reference', 191);
            $table->foreignId('form_version_id')
                ->constrained('form_versions')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['form_definition_id', 'context_key', 'context_reference'],
                'form_context_versions_identity_unique',
            );
            $table->index('form_version_id', 'form_context_versions_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_context_versions');
    }
};