<?php

use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(ContactStatus::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true)->index();

            $table->string('preset_key')->nullable()->index();
            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['contact_status_id', 'version']);
            $table->unique(['preset_key', 'version']);

            $table->index(['contact_status_id', 'is_active']);
            $table->index(['preset_key', 'source_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_routes');
    }
};