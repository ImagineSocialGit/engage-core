<?php

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Campaign::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Logical Messaging bridge only. MessageChain runtime tables are created
            // by later Messaging migrations, so this original Campaigns migration
            // cannot safely add a physical cross-module foreign key.
            // Nullable is required only for the short transaction window in which
            // CampaignEnrollment must exist before it can be the chain context.
            $table->unsignedBigInteger('message_chain_enrollment_id')
                ->nullable()
                ->unique();

            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Retain the stable business key even if the optional Campaign FK is
            // later nulled by deletion/archive maintenance.
            $table->string('campaign_key', 120)->index();

            // Campaign-owned enrollment intent/provenance only. MessageChainEnrollment
            // owns status, current step, timing, pause/resume, exit, and completion.
            $table->json('start_context')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('started_at')->nullable()->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['campaign_id']);
            $table->index(['contact_id', 'campaign_id']);
            $table->index([
                'contact_id',
                'campaign_key',
            ], 'campaign_enrollments_contact_campaign_key_index');
            $table->index([
                'source_id',
                'campaign_key',
            ], 'campaign_enrollments_source_campaign_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
    }
};