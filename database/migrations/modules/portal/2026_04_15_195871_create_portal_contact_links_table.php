<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_contact_links', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(PortalUser::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('relationship')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();

            $table->timestamp('linked_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['portal_user_id', 'status'], 'portal_contact_links_user_status_index');
            $table->index(['contact_id', 'status'], 'portal_contact_links_contact_status_index');
            $table->unique(['portal_user_id', 'contact_id'], 'portal_contact_links_user_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_contact_links');
    }
};
