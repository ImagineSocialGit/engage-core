<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(User::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('role')->nullable()->index();

            $table->boolean('active')
                ->default(true)
                ->index();

            $table->timestamps();

            $table->index(['active', 'email']);
            $table->index(['active', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};