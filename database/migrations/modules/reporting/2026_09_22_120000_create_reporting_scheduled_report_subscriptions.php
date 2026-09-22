<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'reporting_scheduled_report_subscriptions',
            function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid');
                $table->string('report_key', 120);
                $table->string('name', 255);
                $table->string('channel', 32)->default('email');
                $table->json('days_of_week');
                $table->string('send_time', 5);
                $table->string('timezone', 64);
                $table->json('parameters')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('last_sent_at', 6)->nullable();
                $table->timestamp('next_send_at', 6)->nullable();
                $table->timestamps(6);

                $table->unique(
                    'uuid',
                    'report_sched_subscriptions_uuid_unique',
                );
                $table->index(
                    ['is_enabled', 'next_send_at'],
                    'report_sched_subscriptions_due_idx',
                );
                $table->index(
                    ['report_key', 'is_enabled'],
                    'report_sched_subscriptions_report_idx',
                );
            },
        );

        Schema::create(
            'reporting_scheduled_report_recipients',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scheduled_report_subscription_id');
                $table->foreign(
                    'scheduled_report_subscription_id',
                    'report_sched_recipients_subscription_fk',
                )
                    ->references('id')
                    ->on('reporting_scheduled_report_subscriptions')
                    ->cascadeOnDelete();
                $table->string('recipient_type', 255);
                $table->unsignedBigInteger('recipient_id');
                $table->timestamps(6);

                $table->unique(
                    [
                        'scheduled_report_subscription_id',
                        'recipient_type',
                        'recipient_id',
                    ],
                    'report_sched_recipients_identity_unique',
                );
                $table->index(
                    ['recipient_type', 'recipient_id'],
                    'report_sched_recipients_recipient_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_scheduled_report_recipients');
        Schema::dropIfExists('reporting_scheduled_report_subscriptions');
    }
};