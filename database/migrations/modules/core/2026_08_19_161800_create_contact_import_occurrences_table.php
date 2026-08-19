<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_import_batch_id')
                ->constrained('contact_import_batches')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('outcome', 32)->index();
            $table->string('identity_type', 32);
            $table->string('identity_value');
            $table->text('original_source')->nullable();
            $table->text('original_subsource')->nullable();
            $table->text('original_status')->nullable();
            $table->char('row_fingerprint', 64)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['contact_import_batch_id', 'row_number'],
                'contact_import_occurrences_batch_row_unique',
            );
            $table->index(
                ['contact_id', 'contact_import_batch_id'],
                'contact_import_occurrences_contact_batch_index',
            );
            $table->index(
                ['identity_type', 'identity_value'],
                'contact_import_occurrences_identity_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_occurrences');
    }
};