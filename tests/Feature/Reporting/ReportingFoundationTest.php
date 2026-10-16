<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Models\ReportingDailyMetric;
use App\Modules\Reporting\Models\ReportingObservation;
use App\Modules\Reporting\Models\ReportingProjectionCheckpoint;
use App\Modules\Reporting\Models\ReportingSession;
use App\Modules\Reporting\Providers\ReportingModuleServiceProvider;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_remains_core_only_and_owns_a_registered_schema_scope(): void
    {
        $modules = app(ModuleManager::class);
        $registry = app(ModuleMigrationRegistry::class);

        $this->assertTrue($modules->known('reporting'));
        $this->assertEquals(['core'], $modules->dependencies('reporting'));
        $this->assertContains(
            ReportingModuleServiceProvider::class,
            $modules->providers('reporting'),
        );

        $scope = $registry->requireModule('reporting');

        $this->assertSame(
            (string) config('module_migrations.modules.reporting.path'),
            $scope->path,
        );
        $this->assertNotEmpty($scope->migrationFiles);

        foreach ($scope->migrationFiles as $migrationFile) {
            $this->assertSame(
                'reporting',
                $registry->ownerFor($migrationFile)?->key,
            );
        }
    }

    public function test_reporting_models_cast_bounded_json_and_time_fields(): void
    {
        $startedAt = CarbonImmutable::parse('2026-08-15 06:00:00 UTC');

        $session = ReportingSession::query()->create([
            'token_hash' => hash('sha256', 'ephemeral-test-token'),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'started_at' => $startedAt,
            'last_seen_at' => $startedAt->addMinutes(5),
            'absolute_expires_at' => $startedAt->addHours(4),
            'landing_path' => '/homebuyer-basics',
            'referrer_host' => 'search.example.test',
            'utm_source' => 'search',
            'click_id_hashes' => ['approved_click' => hash('sha256', 'click-id')],
            'traffic_class' => 'unknown',
            'classification_reasons' => ['classifier_not_run'],
        ]);

        $observation = ReportingObservation::query()->create([
            'event_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'normalized-event'),
            'reporting_session_id' => $session->id,
            'event_key' => 'page.view',
            'event_version' => 1,
            'source' => 'browser',
            'occurred_at' => $startedAt,
            'received_at' => $startedAt->addSecond(),
            'host' => 'webinar.example.test',
            'surface' => 'webinar_registration',
            'path' => '/homebuyer-basics',
            'traffic_class' => 'unknown',
            'properties' => ['page_revision' => 'revision-1'],
        ]);

        $metric = ReportingDailyMetric::query()->create([
            'metric_date' => '2026-08-15',
            'metric_key' => 'webinar.registration_conversion',
            'metric_version' => 1,
            'dimension_hash' => hash('sha256', 'all'),
            'dimensions' => ['traffic_class' => 'likely_human'],
            'numerator' => 3,
            'denominator' => 10,
            'projected_through' => $startedAt,
        ]);

        $checkpoint = ReportingProjectionCheckpoint::query()->create([
            'projector_key' => 'webinar_funnel',
            'projector_version' => 1,
            'cursor' => '42',
            'window_start' => $startedAt->startOfDay(),
            'window_end' => $startedAt->endOfDay(),
            'projected_through' => $startedAt,
            'meta' => ['source' => 'test'],
        ]);

        $session->refresh();
        $observation->refresh();
        $metric->refresh();
        $checkpoint->refresh();

        $this->assertEquals(
            ['approved_click' => hash('sha256', 'click-id')],
            $session->click_id_hashes,
        );
        $this->assertEquals(['classifier_not_run'], $session->classification_reasons);
        $this->assertTrue($session->started_at?->equalTo($startedAt));
        $this->assertEquals(['page_revision' => 'revision-1'], $observation->properties);
        $this->assertSame($session->id, $observation->session?->id);
        $this->assertEquals(['traffic_class' => 'likely_human'], $metric->dimensions);
        $this->assertSame(3, $metric->numerator);
        $this->assertSame(10, $metric->denominator);
        $this->assertEquals(['source' => 'test'], $checkpoint->meta);
    }

}