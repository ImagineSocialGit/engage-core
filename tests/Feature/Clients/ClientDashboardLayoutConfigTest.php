<?php

namespace Tests\Feature\Clients;

use App\Providers\ClientServiceProvider;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
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