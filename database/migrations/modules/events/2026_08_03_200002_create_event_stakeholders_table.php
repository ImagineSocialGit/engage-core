<?php

use App\Modules\Events\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_stakeholders', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Event::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role_key', 80)->index();
            $table->string('name');
            $table->string('organization')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['event_id', 'role_key'],
                'event_stakeholders_event_role_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_stakeholders');
    }
};