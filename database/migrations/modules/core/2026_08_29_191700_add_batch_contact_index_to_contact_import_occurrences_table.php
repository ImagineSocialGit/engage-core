<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_occurrences', function (Blueprint $table): void {
            $table->index(
                ['contact_import_batch_id', 'contact_id'],
                'contact_import_occurrences_batch_contact_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_occurrences', function (Blueprint $table): void {
            $table->dropIndex('contact_import_occurrences_batch_contact_index');
        });
    }
};