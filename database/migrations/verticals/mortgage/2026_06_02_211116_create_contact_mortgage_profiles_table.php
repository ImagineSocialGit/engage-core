<?php

use App\Models\Contact;
use App\Models\MortgageStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_mortgage_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(MortgageStage::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('title')->nullable();

            $table->decimal('loan_amount', 12, 2)->nullable();

            $table->decimal('rate', 6, 3)->nullable();

            $table->string('mortgage_type')->nullable()->index();

            $table->string('loan_purpose')->nullable()->index();

            $table->string('loan_program')->nullable()->index();

            $table->string('lien_position')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'contact_id',
                'mortgage_stage_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_mortgage_profiles');
    }
};