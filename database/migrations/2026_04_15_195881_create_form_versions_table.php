<?php

use App\Modules\Forms\Models\FormDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(FormDefinition::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();

            $table->json('schema')->nullable();
            $table->json('rules')->nullable();
            $table->json('layout')->nullable();
            $table->json('settings')->nullable();

            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['form_definition_id', 'version'], 'form_versions_definition_version_unique');
            $table->index(['form_definition_id', 'status'], 'form_versions_definition_status_index');
            $table->index(['provider', 'external_id'], 'form_versions_provider_external_index');
        });

        Schema::table('form_definitions', function (Blueprint $table) {
            $table->foreign('current_form_version_id', 'form_definitions_current_version_foreign')
                ->references('id')
                ->on('form_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_definitions', function (Blueprint $table) {
            $table->dropForeign('form_definitions_current_version_foreign');
        });

        Schema::dropIfExists('form_versions');
    }
};