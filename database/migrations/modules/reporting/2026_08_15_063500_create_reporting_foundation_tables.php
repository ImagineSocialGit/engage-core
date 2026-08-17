<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->nullable();
            $table->string('host', 255);
            $table->string('surface', 80);
            $table->timestamp('started_at', 6);
            $table->timestamp('last_seen_at', 6);
            $table->timestamp('absolute_expires_at', 6);
            $table->string('landing_path', 512);
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->string('external_platform', 32)->nullable();
            $table->string('external_campaign_id', 120)->nullable();
            $table->string('external_group_id', 120)->nullable();
            $table->string('external_creative_id', 120)->nullable();
            $table->string('external_placement', 120)->nullable();
            $table->json('click_id_hashes')->nullable();
            $table->string('traffic_class', 32)->default('unknown');
            $table->string('classifier_key', 80)->nullable();
            $table->unsignedSmallInteger('classifier_version')->nullable();
            $table->json('classification_reasons')->nullable();
            $table->string('device_class', 50)->nullable();
            $table->string('browser_family', 80)->nullable();
            $table->string('os_family', 80)->nullable();
            $table->timestamps(6);

            $table->index(
                ['token_hash', 'host', 'last_seen_at'],
                'reporting_sessions_token_host_seen_idx',
            );
            $table->index(
                ['host', 'started_at'],
                'reporting_sessions_host_started_idx',
            );
            $table->index(
                ['surface', 'started_at'],
                'reporting_sessions_surface_started_idx',
            );
        });

        Schema::create('reporting_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->char('payload_hash', 64);
            $table->foreignId('reporting_session_id')
                ->nullable()
                ->constrained('reporting_sessions')
                ->nullOnDelete();
            $table->string('event_key', 100);
            $table->unsignedSmallInteger('event_version');
            $table->string('source', 32);
            $table->timestamp('occurred_at', 6);
            $table->timestamp('received_at', 6);
            $table->string('host', 255);
            $table->string('surface', 80);
            $table->string('path', 512);
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->string('external_platform', 32)->nullable();
            $table->string('external_campaign_id', 120)->nullable();
            $table->string('external_group_id', 120)->nullable();
            $table->string('external_creative_id', 120)->nullable();
            $table->string('external_placement', 120)->nullable();
            $table->json('click_id_hashes')->nullable();
            $table->string('traffic_class', 32)->default('unknown');
            $table->string('classifier_key', 80)->nullable();
            $table->unsignedSmallInteger('classifier_version')->nullable();
            $table->json('classification_reasons')->nullable();
            $table->string('device_class', 50)->nullable();
            $table->string('browser_family', 80)->nullable();
            $table->string('os_family', 80)->nullable();
            $table->json('properties')->nullable();
            $table->timestamps(6);

            $table->unique('event_id', 'reporting_observations_event_id_unique');
            $table->index(
                ['event_key', 'event_version', 'occurred_at'],
                'reporting_observations_event_time_idx',
            );
            $table->index(
                ['surface', 'occurred_at'],
                'reporting_observations_surface_time_idx',
            );
            $table->index(
                ['traffic_class', 'occurred_at'],
                'reporting_observations_traffic_time_idx',
            );
            $table->index(
                ['reporting_session_id', 'occurred_at'],
                'reporting_observations_session_time_idx',
            );
        });

        Schema::create('reporting_external_measurements', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('platform', 32);
            $table->string('account_id', 120)->nullable();
            $table->string('account_timezone', 64)->nullable();
            $table->string('campaign_id', 120)->nullable();
            $table->string('group_id', 120)->nullable();
            $table->string('creative_id', 120)->nullable();
            $table->string('campaign_name', 255)->nullable();
            $table->string('group_name', 255)->nullable();
            $table->string('creative_name', 255)->nullable();
            $table->string('placement', 120)->nullable();
            $table->string('identity_quality', 24);
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('link_clicks')->nullable();
            $table->unsignedBigInteger('outbound_clicks')->nullable();
            $table->unsignedBigInteger('landing_page_views')->nullable();
            $table->decimal('spend', 16, 4)->nullable();
            $table->string('result_type', 80)->nullable();
            $table->decimal('results', 20, 6)->nullable();
            $table->string('source', 32);
            $table->char('source_file_hash', 64)->nullable();
            $table->json('meta')->nullable();
            $table->char('identity_hash', 64);
            $table->timestamp('imported_at', 6);
            $table->timestamps(6);

            $table->unique(
                'identity_hash',
                'reporting_external_measurements_identity_unique',
            );
            $table->index(
                ['platform', 'period_start', 'period_end'],
                'reporting_external_measurements_platform_period_idx',
            );
            $table->index(
                ['platform', 'campaign_id', 'period_start'],
                'reporting_external_measurements_campaign_period_idx',
            );
            $table->index(
                ['identity_quality', 'period_start'],
                'reporting_external_measurements_quality_period_idx',
            );
        });

        Schema::create('reporting_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('metric_date');
            $table->string('metric_key', 100);
            $table->unsignedSmallInteger('metric_version');
            $table->char('dimension_hash', 64);
            $table->json('dimensions')->nullable();
            $table->unsignedBigInteger('numerator')->default(0);
            $table->unsignedBigInteger('denominator')->nullable();
            $table->timestamp('projected_through', 6)->nullable();
            $table->timestamps(6);

            $table->unique(
                ['metric_date', 'metric_key', 'metric_version', 'dimension_hash'],
                'reporting_daily_metrics_identity_unique',
            );
            $table->index(
                ['metric_key', 'metric_version', 'metric_date'],
                'reporting_daily_metrics_metric_date_idx',
            );
        });

        Schema::create('reporting_projection_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('projector_key', 100);
            $table->unsignedSmallInteger('projector_version');
            $table->string('cursor', 255)->nullable();
            $table->timestamp('window_start', 6)->nullable();
            $table->timestamp('window_end', 6)->nullable();
            $table->timestamp('projected_through', 6)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['projector_key', 'projector_version'],
                'reporting_projection_checkpoints_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_projection_checkpoints');
        Schema::dropIfExists('reporting_daily_metrics');
        Schema::dropIfExists('reporting_external_measurements');
        Schema::dropIfExists('reporting_observations');
        Schema::dropIfExists('reporting_sessions');
    }
};