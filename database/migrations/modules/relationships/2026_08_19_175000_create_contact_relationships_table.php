<?php

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Contact::class);
            $table->string('relationship_key', 120);
            $table->string('stage_key', 120)->nullable();
            $table->string('source', 120)->nullable();
            $table->string('subsource', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('contact_id', 'contact_rel_contact_fk')
                ->references('id')
                ->on('contacts')
                ->cascadeOnDelete();

            $table->unique(
                ['contact_id', 'relationship_key'],
                'contact_rel_contact_key_uq',
            );

            $table->index(
                ['relationship_key', 'is_active'],
                'contact_rel_key_active_idx',
            );

            $table->index(
                ['relationship_key', 'stage_key', 'is_active'],
                'contact_rel_stage_active_idx',
            );

            $table->index('source', 'contact_rel_source_idx');
            $table->index('subsource', 'contact_rel_subsource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_relationships');
    }
};