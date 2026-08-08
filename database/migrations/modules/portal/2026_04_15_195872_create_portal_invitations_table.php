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
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(PortalUser::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('token_hash')->unique();

            $table->string('status')->default('pending')->index();
            $table->string('channel')->nullable()->index();
            $table->string('purpose')->nullable()->index();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->string('accepted_ip')->nullable();
            $table->text('accepted_user_agent')->nullable();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at'], 'portal_invitations_status_expires_index');
            $table->index(['contact_id', 'status'], 'portal_invitations_contact_status_index');
            $table->index(['portal_user_id', 'status'], 'portal_invitations_user_status_index');
            $table->index(['provider', 'external_id'], 'portal_invitations_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
    }
};
