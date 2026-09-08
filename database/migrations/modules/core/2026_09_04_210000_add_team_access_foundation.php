<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('user_access_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('role_key', 64)->default('member')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('capability_overrides')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['team_id', 'user_id']);
            $table->index(['user_id', 'team_id']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('contact_import_batch_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('assigned_team_id')
                ->nullable()
                ->after('assigned_user_id')
                ->constrained('teams')
                ->nullOnDelete();
        });

        $now = now();

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now): void {
                $rows = collect($users)->map(fn ($user): array => [
                    'user_id' => $user->id,
                    'role_key' => 'owner',
                    'is_active' => true,
                    'capability_overrides' => null,
                    'meta' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('user_access_profiles')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_team_id');
            $table->dropConstrainedForeignId('assigned_user_id');
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('user_access_profiles');
        Schema::dropIfExists('teams');
    }
};