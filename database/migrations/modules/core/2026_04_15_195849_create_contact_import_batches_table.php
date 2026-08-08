<?php

use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_batches', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();
            $table->string('source')->nullable()->index();
            $table->string('original_filename')->nullable();
            $table->string('status')->default(ContactImportBatch::STATUS_COMPLETED)->index();

            $table->timestamp('imported_at')->nullable()->index();

            $table->unsignedInteger('contact_count')->default(0);
            $table->unsignedInteger('successful_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_batches');
    }
};
