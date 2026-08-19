<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\HasRealtorState;
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
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('has_realtor', 16)
                ->default(HasRealtorState::Unknown->value)
                ->index();

            $table->timestamp('original_lead_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_mortgage_profiles');
    }
};