<?php

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('tag')->index();

            $table->timestamps();

            $table->unique(['contact_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tags');
    }
};