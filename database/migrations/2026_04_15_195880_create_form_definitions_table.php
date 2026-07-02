<?php

use App\Modules\Forms\Models\FormVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('category')->nullable()->index();
            $table->boolean('is_public')->default(false)->index();
            $table->foreignIdFor(FormVersion::class, 'current_form_version_id')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category'], 'form_definitions_status_category_index');
            $table->index(['provider', 'external_id'], 'form_definitions_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_definitions');
    }
};