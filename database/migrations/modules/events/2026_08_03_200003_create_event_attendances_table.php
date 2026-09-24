<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_attendances', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Event::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status', 32)->index();
            $table->timestamp('observed_at')->index();
            $table->string('source_key', 80)->index();
            $table->string('source_reference', 191)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['event_id', 'contact_id'],
                'event_attendances_event_contact_unique',
            );

            $table->index(
                ['event_id', 'status'],
                'event_attendances_event_status_index',
            );

            $table->index(
                ['contact_id', 'observed_at'],
                'event_attendances_contact_observed_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendances');
    }
};