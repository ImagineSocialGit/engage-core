<?php

namespace Tests\Feature\Reporting;

use App\Modules\InternalNotifications\Actions\SaveInternalNotificationRecipientAction;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\Reporting\Actions\ProcessDueScheduledReportsAction;
use App\Modules\Reporting\Actions\SaveScheduledReportSubscriptionAction;
use App\Modules\Reporting\Contracts\ScheduledReportDeliveryDriver;
use App\Modules\Reporting\Contracts\ScheduledReportProvider;
use App\Modules\Reporting\Contracts\ScheduledReportRecipientOptionProvider;
use App\Modules\Reporting\Data\ScheduledReportRecipientOption;
use App\Modules\Reporting\Data\ScheduledReportResult;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Models\ScheduledReportSubscriptionRecipient;
use App\Modules\Reporting\Services\ScheduledReportDeliveryRegistry;
use App\Modules\Reporting\Services\ScheduledReportRecipientRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use App\Modules\Reporting\Services\ScheduledReportScheduleCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledReportSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_schedule_owns_recipients_days_time_timezone_and_report_parameters(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00 UTC');

        $recipient = app(SaveInternalNotificationRecipientAction::class)->handle(
            name: 'Report Recipient',
            email: 'reports@example.test',
            isActive: true,
            receiveInboundReplies: false,
            receiveScheduledReports: true,
        );

        $provider = new class implements ScheduledReportProvider {
            public function key(): string { return 'test_report'; }
            public function label(): string { return 'Test report'; }
            public function description(): string { return 'Test'; }
            public function settingsView(): string { return 'test'; }
            public function settingsData(array $parameters = []): array { return []; }
            public function defaultParameters(): array { return ['segment' => 'all']; }
            public function normalizeParameters(array $input): array
            {
                return ['segment' => (string) ($input['segment'] ?? 'all')];
            }
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

        $recipientProvider = new class($recipient) implements ScheduledReportRecipientOptionProvider {
            private readonly object $recipient;
            public function __construct(object $recipient) { $this->recipient = $recipient; }
            public function options(): iterable
            {
                yield new ScheduledReportRecipientOption(
                    key: $this->recipient->getMorphClass().':'.$this->recipient->getKey(),
                    recipientType: $this->recipient->getMorphClass(),
                    recipientId: (int) $this->recipient->getKey(),
                    label: 'Report Recipient',
                    email: 'reports@example.test',
                );
            }
        };

        $action = new SaveScheduledReportSubscriptionAction(
            reports: new ScheduledReportRegistry([$provider]),
            recipients: new ScheduledReportRecipientRegistry([$recipientProvider]),
            schedule: new ScheduledReportScheduleCalculator(),
        );

        $subscription = $action->handle(
            reportKey: 'test_report',
            name: 'Weekday report',
            recipientKeys: [
                $recipient->getMorphClass().':'.$recipient->getKey(),
            ],
            daysOfWeek: [1, 2, 3, 4, 5],
            sendTime: '08:00',
            timezone: 'America/New_York',
            parameters: ['segment' => 'prospects'],
            isEnabled: true,
        );

        $this->assertSame('test_report', $subscription->report_key);
        $this->assertSame([1, 2, 3, 4, 5], $subscription->days_of_week);
        $this->assertSame('08:00', $subscription->send_time);
        $this->assertSame('America/New_York', $subscription->timezone);
        $this->assertSame(
            ['segment' => 'prospects'],
            $subscription->parameters,
        );
        $this->assertNotNull($subscription->next_send_at);
        $this->assertDatabaseHas('reporting_scheduled_report_recipients', [
            'scheduled_report_subscription_id' => $subscription->getKey(),
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
        ]);
        $this->assertDatabaseHas('team_member_notification_preferences', [
            'team_member_id' => $recipient->getKey(),
            'purpose' => TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
            'is_enabled' => true,
        ]);
    }

    public function test_due_processor_delivers_once_and_advances_the_schedule(): void
    {
        Carbon::setTestNow('2026-09-22 13:00:00 UTC');

        $subscription = ScheduledReportSubscription::query()->create([
            'report_key' => 'test_report',
            'name' => 'Due report',
            'channel' => 'email',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'send_time' => '08:00',
            'timezone' => 'America/New_York',
            'parameters' => [],
            'is_enabled' => true,
            'next_send_at' => now()->subMinute(),
        ]);
        $recipient = $subscription->recipients()->create([
            'recipient_type' => 'test-recipient',
            'recipient_id' => 10,
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
                    subject: 'Due',
                    headline: 'Due',
                    preheader: 'Due',
                    body: ['Due'],
                );
            }
        };

        $deliveries = 0;
        $driver = new class($deliveries) implements ScheduledReportDeliveryDriver {
            public int $deliveries = 0;
            public function __construct(int $deliveries) { $this->deliveries = $deliveries; }
            public function supports(string $recipientType, string $channel): bool
            {
                return $recipientType === 'test-recipient' && $channel === 'email';
            }
            public function deliver(
                ScheduledReportSubscription $subscription,
                ScheduledReportSubscriptionRecipient $recipient,
                ScheduledReportResult $result,
                string $occurrenceKey,
            ): bool {
                $this->deliveries++;
                return true;
            }
        };

        $action = new ProcessDueScheduledReportsAction(
            reports: new ScheduledReportRegistry([$provider]),
            delivery: new ScheduledReportDeliveryRegistry([$driver]),
            schedule: new ScheduledReportScheduleCalculator(),
        );

        $this->assertSame(1, $action->handle());
        $this->assertSame(1, $driver->deliveries);

        $subscription->refresh();

        $this->assertNotNull($subscription->last_sent_at);
        $this->assertTrue($subscription->next_send_at->isFuture());
    }
}