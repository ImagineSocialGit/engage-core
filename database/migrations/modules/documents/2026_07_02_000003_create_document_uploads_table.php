<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_uploads', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(DocumentRequest::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(DocumentRequirementDefinition::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('subject');
            $table->nullableMorphs('uploaded_by');

            $table->foreignIdFor(DocumentUpload::class, 'replaces_document_upload_id')
                ->nullable()
                ->constrained('document_uploads')
                ->nullOnDelete();

            $table->string('title')->nullable();
            $table->string('status')->default('uploaded')->index();
            $table->string('review_status')->default('pending')->index();

            $table->string('disk')->nullable()->index();
            $table->string('path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable()->index();
            $table->string('extension')->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->nullable()->index();
            $table->string('checksum')->nullable()->index();
            $table->string('storage_visibility')->default('private')->index();

            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamp('rejected_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->json('metadata')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_request_id', 'status'], 'document_uploads_request_status_index');
            $table->index(['document_requirement_definition_id', 'status'], 'document_uploads_requirement_status_index');
            $table->index(['contact_id', 'status'], 'document_uploads_contact_status_index');
            $table->index(['review_status', 'submitted_at'], 'document_uploads_review_submitted_index');
            $table->index(['provider', 'external_id'], 'document_uploads_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_uploads');
    }
};
