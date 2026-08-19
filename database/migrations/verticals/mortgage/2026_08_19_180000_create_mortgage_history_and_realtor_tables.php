<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Models\MortgageRealtorProfile;
use App\Modules\Mortgage\Models\MortgageStage;
use App\Modules\Relationships\Models\ContactRelationship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mortgage_loans', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(MortgageStage::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('source_system', 120)->nullable()->index();
            $table->string('source_record_id', 191)->nullable()->index();
            $table->char('source_fingerprint', 64)->nullable()->index();
            $table->string('loan_originator')->nullable()->index();
            $table->string('loan_purpose')->nullable()->index();
            $table->string('loan_program')->nullable()->index();
            $table->string('mortgage_type')->nullable()->index();
            $table->string('lien_position')->nullable()->index();
            $table->decimal('loan_amount', 15, 2)->nullable();
            $table->decimal('note_rate', 7, 4)->nullable();
            $table->decimal('sales_price', 15, 2)->nullable();
            $table->decimal('appraised_value', 15, 2)->nullable();
            $table->decimal('cash_to_close', 15, 2)->nullable();
            $table->string('subject_property_street')->nullable();
            $table->string('subject_property_city')->nullable();
            $table->string('subject_property_state', 64)->nullable()->index();
            $table->string('subject_property_zip', 32)->nullable()->index();
            $table->date('closed_on')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index([
                'source_system',
                'source_record_id',
            ]);
        });

        Schema::create('mortgage_loan_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mortgage_loan_id')
                ->constrained('mortgage_loans')
                ->cascadeOnDelete();
            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('role', 40)->index();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 64)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('mailing_address')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique([
                'mortgage_loan_id',
                'role',
                'position',
            ], 'mortgage_loan_participant_role_position_unique');
            $table->index([
                'contact_id',
                'role',
            ]);
        });

        Schema::create('mortgage_loan_realtors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mortgage_loan_id')
                ->constrained('mortgage_loans')
                ->cascadeOnDelete();
            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('role', 40)->index();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique([
                'mortgage_loan_id',
                'role',
                'position',
            ], 'mortgage_loan_realtor_role_position_unique');
            $table->index([
                'contact_id',
                'role',
            ]);
        });

        Schema::create('mortgage_realtor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ContactRelationship::class);
            $table->string('brokerage_name')->nullable()->index();
            $table->string('license_number', 120)->nullable()->index();
            $table->timestamp('last_referral_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign(
                'contact_relationship_id',
                'mortgage_realtor_rel_fk',
            )
                ->references('id')
                ->on('contact_relationships')
                ->cascadeOnDelete();

            $table->unique(
                'contact_relationship_id',
                'mortgage_realtor_rel_uq',
            );
        });


        Schema::create('mortgage_realtor_production_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(MortgageRealtorProfile::class)
                ->constrained(
                    table: 'mortgage_realtor_profiles',
                    indexName: 'mr_prod_snapshot_realtor_profile_fk',
                )
                ->cascadeOnDelete();
            $table->date('period_ending_on')->nullable()->index();
            $table->unsignedSmallInteger('period_months')->default(12);
            $table->unsignedInteger('loan_count')->nullable();
            $table->unsignedInteger('conventional_count')->nullable();
            $table->unsignedInteger('va_count')->nullable();
            $table->decimal('loan_volume', 16, 2)->nullable();
            $table->string('source', 191)->nullable()->index();
            $table->char('source_fingerprint', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index([
                'mortgage_realtor_profile_id',
                'period_ending_on',
            ], 'mortgage_realtor_profile_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mortgage_realtor_production_snapshots');
        Schema::dropIfExists('mortgage_realtor_profiles');
        Schema::dropIfExists('mortgage_loan_realtors');
        Schema::dropIfExists('mortgage_loan_participants');
        Schema::dropIfExists('mortgage_loans');
    }
};