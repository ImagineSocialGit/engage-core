<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(DocumentRequirementDefinition::class)
                ->nullable()
                ->constrained(indexName: 'document_requests_requirement_fk')
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('subject');
            $table->nullableMorphs('requested_by');
            $table->nullableMorphs('assigned_to');

            $table->string('title');
            $table->text('instructions')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('request_token')->nullable()->unique();

            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('opened_at')->nullable()->index();
            $table->timestamp('first_uploaded_at')->nullable()->index();
            $table->timestamp('last_uploaded_at')->nullable()->index();
            $table->timestamp('satisfied_at')->nullable()->index();
            $table->timestamp('waived_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('settings')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_requirement_definition_id', 'status'], 'document_requests_requirement_status_index');
            $table->index(['contact_id', 'status'], 'document_requests_contact_status_index');
            $table->index(['status', 'expires_at'], 'document_requests_status_expires_index');
            $table->index(['provider', 'external_id'], 'document_requests_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
