<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_hosts', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();

            $table->nullableMorphs('hostable');

            $table->string('timezone')->default('UTC');
            $table->unsignedInteger('capacity')->default(1);

            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();

            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('source')->default('manual')->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['status', 'sort_order'],
                'scheduling_hosts_status_sort_order_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_hosts');
    }
};