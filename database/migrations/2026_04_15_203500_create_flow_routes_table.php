<?php

use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(ContactStatus::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('version')->default(1);

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique('contact_status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_routes');
    }
};