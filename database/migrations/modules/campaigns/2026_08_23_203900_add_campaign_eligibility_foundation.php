<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->json('eligibility_filter')
                ->nullable()
                ->after('priority');
            $table->string('enrollment_mode', 32)
                ->default('manual')
                ->after('eligibility_filter')
                ->index();
            $table->string('reentry_policy', 32)
                ->default('never')
                ->after('enrollment_mode');
            $table->string('ineligible_behavior', 32)
                ->default('continue')
                ->after('reentry_policy');

            $table->index(
                ['status', 'enrollment_mode'],
                'campaign_status_enrollment_mode_idx',
            );
        });

        Schema::create('campaign_eligibility_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->boolean('is_eligible')->default(false);
            $table->unsignedInteger('eligibility_cycle')->default(0);
            $table->timestamp('became_eligible_at')->nullable();
            $table->timestamp('became_ineligible_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['campaign_id', 'contact_id'],
                'campaign_eligibility_state_unique',
            );
            $table->index(
                ['contact_id', 'is_eligible'],
                'campaign_eligibility_contact_state_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_eligibility_states');

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaign_status_enrollment_mode_idx');
            $table->dropIndex(['enrollment_mode']);
            $table->dropColumn([
                'eligibility_filter',
                'enrollment_mode',
                'reentry_policy',
                'ineligible_behavior',
            ]);
        });
    }
};