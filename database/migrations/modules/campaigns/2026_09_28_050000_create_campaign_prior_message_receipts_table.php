<?php

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_prior_message_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->string('message_step_key', 128);
            $table->string('evidence_source', 48);
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignIdFor(User::class, 'attested_by')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['contact_id', 'campaign_id', 'message_step_key'],
                'campaign_prior_receipt_contact_step_uq',
            );
            $table->index(['source_type', 'source_id'], 'campaign_prior_receipt_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_prior_message_receipts');
    }
};