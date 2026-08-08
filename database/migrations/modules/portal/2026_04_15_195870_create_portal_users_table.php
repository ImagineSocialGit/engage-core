<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_users', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();

            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('password')->nullable();
            $table->rememberToken();

            $table->string('status')->default('invited')->index();

            $table->timestamp('email_verified_at')->nullable()->index();
            $table->timestamp('phone_verified_at')->nullable()->index();
            $table->timestamp('last_login_at')->nullable()->index();
            $table->timestamp('invited_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('disabled_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'email'], 'portal_users_status_email_index');
            $table->index(['provider', 'external_id'], 'portal_users_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_users');
    }
};
