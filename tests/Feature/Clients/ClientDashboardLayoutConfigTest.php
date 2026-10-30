<?php

namespace Tests\Feature\Clients;

use App\Providers\ClientServiceProvider;
use App\Support\Dashboard\Contracts\DashboardMetricProvider;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use App\Support\Dashboard\DashboardMetricRegistry;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientDashboardLayoutConfigTest extends TestCase
{
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_client_dashboard_config_overrides_selected_preset_order_and_priority(): void
    {
        $root = $this->makeTempDirectory();

        file_put_contents($root.'/client.php', <<<'PHP'
<?php

return [
    'dashboard' => [
        'slots' => [
            'immediate_work' => [
                'max' => 2,
                'panels' => [
                    'dashboard.test.first',
                    'dashboard.test.second',
                ],
                'priorities' => [
                    'dashboard.test.second' => 200,
                ],
            ],
        ],
    ],
];
PHP);

        config()->set('client.config_path', $root);
        config()->set('client.preset', 'dashboard_test');
        config()->set('modules.enabled', ['core']);
        config()->set('modules.dashboard.slots', [
            'immediate_work' => [
                'max' => 1,
                'hide_when_empty' => false,
                'panels' => [
                    'dashboard.test.second',
                    'dashboard.test.first',
                ],
            ],
            'context' => [
                'max' => 0,
                'panels' => [],
            ],
        ]);
        config()->set('modules.dashboard.presets.dashboard_test.slots', [
            'immediate_work' => [
                'max' => 1,
                'panels' => [
                    'dashboard.test.second',
                ],
            ],
        ]);

        (new ClientServiceProvider($this->app))->register();

        $this->app->tag([
            FirstDashboardTestPanelProvider::class,
            SecondDashboardTestPanelProvider::class,
        ], DashboardPanelRegistry::providerTag());

        $panels = app(DashboardPanelRegistry::class)
            ->panelsFor(Request::create('/'))
            ->get('immediate_work');

        $this->assertSame(2, config('client.dashboard.slots.immediate_work.max'));
        $this->assertEquals(
            [
                'dashboard.test.first',
                'dashboard.test.second',
            ],
            config('client.dashboard.slots.immediate_work.panels'),
        );

        $priorityOverrides = config(
            'client.dashboard.slots.immediate_work.priorities',
            [],
        );

        $this->assertIsArray($priorityOverrides);
        $this->assertSame(
            200,
            $priorityOverrides['dashboard.test.second'] ?? null,
        );
        $this->assertEquals(
            [
                'dashboard.test.second',
                'dashboard.test.first',
            ],
            $panels?->pluck('key')->values()->all(),
        );
    }

    public function test_dashboard_metric_config_resolves_currency_count_and_client_priority(): void
    {
        config()->set('modules.enabled', ['dashboard_test']);
        config()->set('modules.dashboard.metrics', [
            'keys' => ['dashboard.test.count'],
            'max' => 1,
        ]);
        config()->set('client.dashboard.metrics', [
            'keys' => ['dashboard.test.count', 'dashboard.test.currency'],
            'max' => 2,
            'priorities' => ['dashboard.test.currency' => 200],
        ]);

        $this->app->tag([
            CountDashboardTestMetricProvider::class,
            CurrencyDashboardTestMetricProvider::class,
        ], DashboardMetricRegistry::providerTag());

        $metrics = app(DashboardMetricRegistry::class)->metricsFor(Request::create('/'));

        $this->assertSame(
            ['dashboard.test.currency', 'dashboard.test.count'],
            $metrics->pluck('key')->values()->all(),
        );
        $this->assertSame('$12,450.00', $metrics->first()['display_value']);
        $this->assertSame('1,203', $metrics->last()['display_value']);

        config()->set('client.dashboard.metrics.keys', ['dashboard.test.currency']);
        config()->set('modules.enabled', []);

        $this->assertTrue(
            app(DashboardMetricRegistry::class)->metricsFor(Request::create('/'))->isEmpty(),
        );
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/engage-core-dashboard-config-'.bin2hex(random_bytes(8));

        mkdir($directory, 0777, true);

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            is_dir($path)
                ? $this->deleteDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}

final class FirstDashboardTestPanelProvider implements DashboardPanelProvider
{
    public function key(): string
    {
        return 'dashboard.test.first';
    }

    public function module(): string
    {
        return 'core';
    }

    public function panel(Request $request): array
    {
        return [
            'count' => 1,
            'priority' => 10,
            'items' => [['key' => 'first']],
        ];
    }
}

final class SecondDashboardTestPanelProvider implements DashboardPanelProvider
{
    public function key(): string
    {
        return 'dashboard.test.second';
    }

    public function module(): string
    {
        return 'core';
    }

    public function panel(Request $request): array
    {
        return [
            'count' => 1,
            'priority' => 20,
            'items' => [['key' => 'second']],
        ];
    }
}


final class CountDashboardTestMetricProvider implements DashboardMetricProvider
{
    public function key(): string
    {
        return 'dashboard.test.count';
    }

    public function module(): string
    {
        return 'dashboard_test';
    }

    public function metric(Request $request): array
    {
        return ['label' => 'Contacts', 'format' => 'count', 'value' => 1203];
    }
}

final class CurrencyDashboardTestMetricProvider implements DashboardMetricProvider
{
    public function key(): string
    {
        return 'dashboard.test.currency';
    }

    public function module(): string
    {
        return 'dashboard_test';
    }

    public function metric(Request $request): array
    {
        return [
            'label' => 'Received',
            'format' => 'currency',
            'value' => 1245000,
            'currency' => 'USD',
            'period' => 'This month',
            'href' => '/payments',
        ];
    }
}