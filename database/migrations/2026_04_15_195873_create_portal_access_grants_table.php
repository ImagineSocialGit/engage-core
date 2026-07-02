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
        Schema::create('portal_access_grants', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(PortalUser::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('grantable');

            $table->string('capability')->index();
            $table->string('status')->default('active')->index();

            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->nullableMorphs('granted_by');
            $table->timestamp('revoked_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['portal_user_id', 'status', 'capability'], 'portal_access_grants_user_status_capability_index');
            $table->index(['contact_id', 'status', 'capability'], 'portal_access_grants_contact_status_capability_index');
            $table->index(['grantable_type', 'grantable_id', 'capability'], 'portal_access_grants_grantable_capability_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_access_grants');
    }
};
