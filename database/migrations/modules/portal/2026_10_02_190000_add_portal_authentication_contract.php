<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('portal_users')
            ->selectRaw('LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS aggregate')
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_email')
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->values()
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Portal authentication requires unique email login identities. Resolve duplicate Portal emails before migrating.',
            );
        }

        DB::table('portal_users')
            ->whereNotNull('email')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $email = is_string($user->email)
                        ? Str::lower(trim($user->email))
                        : '';

                    DB::table('portal_users')
                        ->where('id', $user->id)
                        ->update([
                            'email' => $email !== '' ? $email : null,
                        ]);
                }
            });

        Schema::table('portal_users', function (Blueprint $table): void {
            $table->dropIndex('portal_users_email_index');
            $table->unique('email', 'portal_users_email_unique');
        });

        Schema::create('portal_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_password_reset_tokens');

        Schema::table('portal_users', function (Blueprint $table): void {
            $table->dropUnique('portal_users_email_unique');
            $table->index('email', 'portal_users_email_index');
        });
    }
};