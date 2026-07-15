<?php

namespace Tests\Feature\Clients;

use App\Providers\ClientServiceProvider;
use Tests\TestCase;

class ClientConfigFallbackTest extends TestCase
{
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_default_config_is_used_when_client_override_is_missing(): void
    {
        $root = $this->makeTempDirectory();

        config([
            'client.config_path' => $root,
            'client.preset' => null,
            'messaging.permission_invitations.content.heading' => 'Default heading',
            'messaging.permission_invitations.content.body' => 'Default body',
        ]);

        (new ClientServiceProvider($this->app))->register();

        $this->assertSame('Default heading', config('messaging.permission_invitations.content.heading'));
        $this->assertSame('Default body', config('messaging.permission_invitations.content.body'));
    }

    public function test_client_override_merges_without_dropping_default_nested_keys(): void
    {
        $root = $this->makeTempDirectory();

        mkdir($root.'/messaging', 0777, true);

        file_put_contents($root.'/messaging/permission_invitations.php', <<<'PHP'
<?php

return [
    'content' => [
        'heading' => 'Client heading',
    ],
    'style' => [
        'button' => 'client-button',
    ],
    'consent' => [
        'scopes' => [
            'broadcast',
        ],
    ],
];
PHP);

        config([
            'client.config_path' => $root,
            'client.preset' => null,
            'messaging.permission_invitations.content.heading' => 'Default heading',
            'messaging.permission_invitations.content.body' => 'Default body',
            'messaging.permission_invitations.style.button' => 'default-button',
            'messaging.permission_invitations.style.card' => 'default-card',
            'messaging.permission_invitations.consent.scopes' => [
                'broadcast',
                'campaign',
            ],
        ]);

        (new ClientServiceProvider($this->app))->register();

        $this->assertSame('Client heading', config('messaging.permission_invitations.content.heading'));
        $this->assertSame('Default body', config('messaging.permission_invitations.content.body'));
        $this->assertSame('client-button', config('messaging.permission_invitations.style.button'));
        $this->assertSame('default-card', config('messaging.permission_invitations.style.card'));
        $this->assertSame(['broadcast'], config('messaging.permission_invitations.consent.scopes'));
    }

    public function test_sparse_numeric_client_arrays_replace_defaults_instead_of_merging(): void
    {
        $root = $this->makeTempDirectory();

        mkdir($root.'/messaging/email/definitions/marketing', 0777, true);

        file_put_contents($root.'/messaging/email/definitions/marketing/webinar_nurture.php', <<<'PHP'
    <?php

    return [
        'campaigns' => [
            'webinar_attended_nurture' => [
                'steps' => [
                    1 => [
                        'variants' => [
                            'email' => ['subject' => 'Client step 1'],
                        ],
                    ],
                    4 => [
                        'variants' => [
                            'email' => ['subject' => 'Client step 4'],
                        ],
                    ],
                ],
            ],
        ],
    ];
    PHP);

        config([
            'client.config_path' => $root,
            'client.preset' => null,
            'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps' => [
                1 => [
                    'variants' => [
                        'email' => ['subject' => 'Default step 1'],
                        'sms' => ['message' => 'Default step 1 SMS'],
                    ],
                ],
                3 => [
                    'variants' => [
                        'email' => ['subject' => 'Default step 3'],
                    ],
                ],
            ],
        ]);

        (new ClientServiceProvider($this->app))->register();

        $this->assertSame(
            [
                1 => [
                    'variants' => [
                        'email' => ['subject' => 'Client step 1'],
                    ],
                ],
                4 => [
                    'variants' => [
                        'email' => ['subject' => 'Client step 4'],
                    ],
                ],
            ],
            config('messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps'),
        );
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/engage-core-client-config-'.bin2hex(random_bytes(8));

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
