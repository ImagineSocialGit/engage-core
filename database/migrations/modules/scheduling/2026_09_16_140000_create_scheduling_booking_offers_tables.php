<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_booking_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bookable_service_id')
                ->constrained('bookable_services')
                ->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('status', 40)->default('inactive')->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedInteger('claim_limit')->nullable();
            $table->text('ineligible_message')->nullable();
            $table->text('exhausted_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['bookable_service_id', 'code'],
                'sched_booking_offers_service_code_unique',
            );
        });

        Schema::create('scheduling_booking_offer_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduling_booking_offer_id');
            $table->foreign(
                'scheduling_booking_offer_id',
                'sched_offer_conditions_offer_fk',
            )
                ->references('id')
                ->on('scheduling_booking_offers')
                ->cascadeOnDelete();
            $table->string('provider', 80);
            $table->json('criteria');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['scheduling_booking_offer_id', 'sort_order'],
                'sched_offer_conditions_sort_index',
            );
        });

        Schema::create('scheduling_booking_offer_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduling_booking_offer_id');
            $table->foreign(
                'scheduling_booking_offer_id',
                'sched_offer_rewards_offer_fk',
            )
                ->references('id')
                ->on('scheduling_booking_offers')
                ->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('max_claim_number');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['scheduling_booking_offer_id', 'max_claim_number'],
                'sched_offer_rewards_threshold_index',
            );
        });

        Schema::create('scheduling_booking_offer_reward_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduling_booking_offer_reward_id');
            $table->foreign(
                'scheduling_booking_offer_reward_id',
                'sched_offer_reward_actions_reward_fk',
            )
                ->references('id')
                ->on('scheduling_booking_offer_rewards')
                ->cascadeOnDelete();
            $table->string('provider', 80);
            $table->json('payload');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['scheduling_booking_offer_reward_id', 'sort_order'],
                'sched_offer_reward_actions_sort_index',
            );
        });

        Schema::create('scheduling_booking_offer_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduling_booking_offer_id');
            $table->foreign(
                'scheduling_booking_offer_id',
                'sched_offer_claims_offer_fk',
            )
                ->references('id')
                ->on('scheduling_booking_offers')
                ->cascadeOnDelete();
            $table->foreignId('appointment_id')
                ->unique()
                ->constrained('appointments')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
            $table->string('qualification_scope_key', 191);
            $table->unsignedInteger('claim_number');
            $table->json('qualification_meta')->nullable();
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(
                [
                    'scheduling_booking_offer_id',
                    'qualification_scope_key',
                    'claim_number',
                ],
                'sched_offer_claims_scope_number_unique',
            );
            $table->unique(
                [
                    'scheduling_booking_offer_id',
                    'qualification_scope_key',
                    'contact_id',
                ],
                'sched_offer_claims_scope_contact_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_booking_offer_claims');
        Schema::dropIfExists('scheduling_booking_offer_reward_actions');
        Schema::dropIfExists('scheduling_booking_offer_rewards');
        Schema::dropIfExists('scheduling_booking_offer_conditions');
        Schema::dropIfExists('scheduling_booking_offers');
    }
};