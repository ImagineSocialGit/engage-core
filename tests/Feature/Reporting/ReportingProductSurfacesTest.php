<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Contracts\ScheduledReportProvider;
use App\Modules\Reporting\Contracts\ScheduledReportRecipientOptionProvider;
use App\Modules\Reporting\Controllers\CRM\ScheduledReportController;
use App\Modules\Reporting\Data\ScheduledReportRecipientOption;
use App\Modules\Reporting\Data\ScheduledReportResult;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Services\ScheduledReportRecipientRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportingProductSurfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_report_preview_route_supports_create_and_edit_forms(): void
    {
        $route = Route::getRoutes()->getByName(
            'crm.reporting.scheduled-reports.preview',
        );

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertContains('PATCH', $route->methods());
    }

    public function test_preview_builds_normalized_report_without_persisting_or_sending(): void
    {
        $provider = new class implements ScheduledReportProvider {
            public function key(): string { return 'preview_report'; }
            public function label(): string { return 'Preview report'; }
            public function description(): string { return 'Preview'; }
            public function settingsView(): string { return 'test'; }
            public function settingsData(array $parameters = []): array { return []; }
            public function defaultParameters(): array { return ['segment' => 'all']; }
            public function normalizeParameters(array $input): array
            {
                return [
                    'segment' => trim((string) ($input['segment'] ?? 'all')),
                ];
            }
            public function build(
                array $parameters,
                CarbonInterface $generatedAt,
                string $timezone,
            ): ScheduledReportResult {
                return new ScheduledReportResult(
                    subject: 'Preview',
                    headline: 'Preview',
                    preheader: 'Preview',
                    body: ['Preview'],
                    meta: [
                        'segment' => $parameters['segment'],
                        'timezone' => $timezone,
                    ],
                );
            }
        };

        $request = Request::create(
            '/reporting/scheduled-reports/preview',
            'POST',
            [
                'report_key' => 'preview_report',
                'timezone' => 'America/Chicago',
                'parameters' => ['segment' => ' prospects '],
            ],
        );

        $view = app(ScheduledReportController::class)->preview(
            request: $request,
            reports: new ScheduledReportRegistry([$provider]),
        );
        $data = $view->getData();

        $this->assertSame(
            ['segment' => 'prospects'],
            $data['parameters'],
        );
        $this->assertInstanceOf(
            ScheduledReportResult::class,
            $data['previewResult'],
        );
        $this->assertSame(
            'prospects',
            $data['previewResult']->meta['segment'],
        );
        $this->assertSame(
            'America/Chicago',
            $data['previewResult']->meta['timezone'],
        );
        $this->assertDatabaseCount(
            'reporting_scheduled_report_subscriptions',
            0,
        );
        $this->assertDatabaseCount(
            'reporting_scheduled_report_recipients',
            0,
        );
    }

    public function test_schedule_index_exposes_operational_state_and_recipient_resolution(): void
    {
        $active = ScheduledReportSubscription::query()->create([
            'report_key' => 'test_report',
            'name' => 'Active report',
            'channel' => 'email',
            'days_of_week' => [1, 3, 5],
            'send_time' => '08:00',
            'timezone' => 'America/Chicago',
            'parameters' => [],
            'is_enabled' => true,
            'next_send_at' => now()->addHour(),
        ]);
        $active->recipients()->create([
            'recipient_type' => 'test-recipient',
            'recipient_id' => 10,
        ]);

        ScheduledReportSubscription::query()->create([
            'report_key' => 'test_report',
            'name' => 'Paused report',
            'channel' => 'email',
            'days_of_week' => [2],
            'send_time' => '09:00',
            'timezone' => 'America/Chicago',
            'parameters' => [],
            'is_enabled' => false,
            'next_send_at' => null,
        ]);

        ScheduledReportSubscription::query()->create([
            'report_key' => 'missing_report',
            'name' => 'Unavailable report',
            'channel' => 'email',
            'days_of_week' => [4],
            'send_time' => '10:00',
            'timezone' => 'America/Chicago',
            'parameters' => [],
            'is_enabled' => true,
            'next_send_at' => now()->addHours(2),
        ]);

        $provider = new class implements ScheduledReportProvider {
            public function key(): string { return 'test_report'; }
            public function label(): string { return 'Test report'; }
            public function description(): string { return 'Test'; }
            public function settingsView(): string { return 'test'; }
            public function settingsData(array $parameters = []): array { return []; }
            public function defaultParameters(): array { return []; }
            public function normalizeParameters(array $input): array { return []; }
            public function build(
                array $parameters,
                CarbonInterface $generatedAt,
                string $timezone,
            ): ScheduledReportResult {
                return new ScheduledReportResult(
                    subject: 'Test',
                    headline: 'Test',
                    preheader: 'Test',
                    body: ['Test'],
                );
            }
        };
        $recipientProvider = new class implements ScheduledReportRecipientOptionProvider {
            public function options(): iterable
            {
                yield new ScheduledReportRecipientOption(
                    key: 'test-recipient:10',
                    recipientType: 'test-recipient',
                    recipientId: 10,
                    label: 'Primary recipient',
                    email: 'reports@example.test',
                );
            }
        };

        $view = app(ScheduledReportController::class)->index(
            reports: new ScheduledReportRegistry([$provider]),
            recipients: new ScheduledReportRecipientRegistry([
                $recipientProvider,
            ]),
        );
        $data = $view->getData();

        $this->assertSame([
            'total' => 3,
            'active' => 1,
            'paused' => 1,
            'attention' => 0,
            'unavailable' => 1,
        ], $data['scheduleSummary']);

        $activeRow = $data['subscriptions']->first(
            fn (array $row): bool =>
                $row['subscription']->is($active),
        );

        $this->assertNotNull($activeRow);
        $this->assertSame('active', $activeRow['state']);
        $this->assertSame(
            ['Monday', 'Wednesday', 'Friday'],
            $activeRow['schedule_days'],
        );
        $this->assertSame(
            'Primary recipient',
            $activeRow['recipient_presentations']->first()['label'],
        );
        $this->assertSame(
            'reports@example.test',
            $activeRow['recipient_presentations']->first()['email'],
        );
    }
}