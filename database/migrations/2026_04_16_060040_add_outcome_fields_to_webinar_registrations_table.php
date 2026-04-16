<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table) {
            $table->timestamp('attended_at')->nullable()->after('registered_at');
            $table->timestamp('converted_at')->nullable()->after('attended_at');
            $table->string('follow_up_status')->nullable()->after('converted_at');

            $table->index('attended_at');
            $table->index('converted_at');
            $table->index('follow_up_status');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table) {
            $table->dropIndex(['attended_at']);
            $table->dropIndex(['converted_at']);
            $table->dropIndex(['follow_up_status']);

            $table->dropColumn([
                'attended_at',
                'converted_at',
                'follow_up_status',
            ]);
        });
    }
};