<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Reporting\Actions\PruneReportingImportFilesAction;
use App\Modules\Reporting\Models\ReportingExternalMeasurement;
use App\Modules\Reporting\Services\ExternalMeasurements\MetaAdsCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportingExternalMeasurementImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_parser_accepts_real_export_shape_and_interprets_dynamic_results(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'meta-reporting-');
        file_put_contents($path, $this->metaCsv());

        try {
            $parsed = app(MetaAdsCsvParser::class)->parse(
                path: $path,
                accountTimezone: 'Central Time',
                sourceFileHash: hash_file('sha256', $path),
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, $parsed['row_count']);
        $this->assertSame(2, $parsed['valid_count']);
        $this->assertSame(0, $parsed['identity_counts']['stable_ids']);
        $this->assertSame(2, $parsed['identity_counts']['name_fallback']);
        $this->assertEquals(['USD'], $parsed['currencies']);
        $this->assertEquals(['2026-07-18 → 2026-08-16'], $parsed['periods']);
        $this->assertNotEmpty($parsed['warnings']);

        $first = $parsed['measurements'][0];
        $second = $parsed['measurements'][1];

        $this->assertSame(356, $first->linkClicks);
        $this->assertNull($first->landingPageViews);
        $this->assertSame('link_click', $first->resultType);
        $this->assertSame('America/Chicago', $first->accountTimezone);
        $this->assertSame('USD', $first->currency);

        $this->assertSame(413, $second->landingPageViews);
        $this->assertNull($second->linkClicks);
        $this->assertSame('landing_page_view', $second->resultType);
    }

    public function test_reporting_import_preview_is_non_mutating_and_reimport_is_idempotent(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $host = 'http://crm.'.config('app.root_domain');

        $preview = $this->actingAs($user)->post(
            $host.'/reporting/imports/preview',
            [
                'csv' => UploadedFile::fake()->createWithContent('meta.csv', $this->metaCsv(oneRow: true)),
                'account_timezone' => 'Central Time',
            ],
        );

        $preview
            ->assertOk()
            ->assertSee('What Reporting recognized')
            ->assertSee('Name fallback')
            ->assertSee('exact automatic ad-to-Engage reconciliation', false);

        $this->assertDatabaseCount('reporting_external_measurements', 0);

        $token = $preview->viewData('importToken');
        $this->assertIsString($token);

        $this->actingAs($user)->post(
            $host.'/reporting/imports',
            [
                'import_token' => $token,
                'account_timezone' => 'Central Time',
            ],
        )
            ->assertRedirect(route('crm.reporting.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('reporting_external_measurements', 1);

        $stored = ReportingExternalMeasurement::query()->sole();
        $this->assertSame('2026-07-18', $stored->period_start?->toDateString());
        $this->assertSame('2026-08-16', $stored->period_end?->toDateString());
        $this->assertSame(ReportingExternalMeasurement::IDENTITY_NAME_FALLBACK, $stored->identity_quality);
        $this->assertSame('USD', $stored->currency);
        $this->assertSame(356, $stored->link_clicks);
        $this->assertSame('America/Chicago', $stored->account_timezone);
        $this->assertSame('not_delivering', $stored->meta['delivery_status']);

        $secondPreview = $this->actingAs($user)->post(
            $host.'/reporting/imports/preview',
            [
                'csv' => UploadedFile::fake()->createWithContent('meta-again.csv', $this->metaCsv(oneRow: true)),
                'account_timezone' => 'Central Time',
            ],
        );

        $this->actingAs($user)->post(
            $host.'/reporting/imports',
            [
                'import_token' => $secondPreview->viewData('importToken'),
                'account_timezone' => 'Central Time',
            ],
        )->assertRedirect(route('crm.reporting.index'));

        $this->assertDatabaseCount('reporting_external_measurements', 1);
    }

    public function test_distinct_meta_csv_snapshots_do_not_overwrite_each_other(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $host = 'http://crm.'.config('app.root_domain');

        $firstPreview = $this->actingAs($user)->post(
            $host.'/reporting/imports/preview',
            [
                'csv' => UploadedFile::fake()->createWithContent('meta-a.csv', $this->metaCsv(oneRow: true)),
                'account_timezone' => 'Central Time',
            ],
        );

        $this->actingAs($user)->post(
            $host.'/reporting/imports',
            [
                'import_token' => $firstPreview->viewData('importToken'),
                'account_timezone' => 'Central Time',
            ],
        )->assertRedirect(route('crm.reporting.index'));

        $differentSnapshot = str_replace(',59.04,', ',60.04,', $this->metaCsv(oneRow: true));

        $secondPreview = $this->actingAs($user)->post(
            $host.'/reporting/imports/preview',
            [
                'csv' => UploadedFile::fake()->createWithContent('meta-b.csv', $differentSnapshot),
                'account_timezone' => 'Central Time',
            ],
        );

        $this->actingAs($user)->post(
            $host.'/reporting/imports',
            [
                'import_token' => $secondPreview->viewData('importToken'),
                'account_timezone' => 'Central Time',
            ],
        )->assertRedirect(route('crm.reporting.index'));

        $this->assertDatabaseCount('reporting_external_measurements', 2);
        $this->assertEqualsCanonicalizing(
            ['59.0400', '60.0400'],
            ReportingExternalMeasurement::query()->pluck('spend')->map(fn ($value): string => (string) $value)->all(),
        );
    }

    public function test_stale_preview_files_are_pruned_without_touching_recent_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reporting-imports/old.csv', 'old');
        Storage::disk('local')->put('reporting-imports/recent.csv', 'recent');
        touch(Storage::disk('local')->path('reporting-imports/old.csv'), time() - 21601);

        $deleted = app(PruneReportingImportFilesAction::class)->handle();

        $this->assertSame(1, $deleted);
        Storage::disk('local')->assertMissing('reporting-imports/old.csv');
        Storage::disk('local')->assertExists('reporting-imports/recent.csv');
    }

    public function test_import_routes_are_hidden_when_reporting_is_disabled(): void
    {
        $user = User::factory()->create();
        config([
            'modules.enabled' => array_values(array_diff(
                config('modules.enabled', []),
                ['reporting'],
            )),
        ]);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/reporting/imports/create')
            ->assertNotFound();
    }

    private function metaCsv(bool $oneRow = false): string
    {
        $header = '"Reporting starts","Reporting ends","Ad name","Ad delivery",Results,"Result indicator","Cost per results","Ad set budget","Ad set budget type","Amount spent (USD)",Impressions,Reach,Ends,"Attribution setting",Bid,"Bid type","Last significant edit","Quality ranking","Engagement rate ranking","Conversion rate ranking","Ad set name","Results (initial)","Results (initial) indicator"';
        $first = '2026-07-18,2026-08-16,"Traffic Ad",not_delivering,356,actions:link_click,0.1658427,20,Daily,59.04,23674,15682,2026-08-10,"7-day click or 1-day view",0,ABSOLUTE_OCPM,2026-08-09T02:13:30-0600,Average,Average,Average,"Traffic Ad Set",,';
        $second = '2026-07-18,2026-08-16,"Landing Page Ad",active,413,actions:omni_landing_page_view,0.43355932,"Using campaign budget",0,179.06,22263,17702,2026-08-17,"7-day click or 1-day view",0,ABSOLUTE_OCPM,2026-08-13T14:17:35-0600,Average,"Above average","Below average - Bottom 35% of ads","Landing Page Ad Set",,';

        return $header."\n".$first."\n".($oneRow ? '' : $second."\n");
    }
}