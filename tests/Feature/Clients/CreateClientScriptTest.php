<?php

namespace Tests\Feature\Clients;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreateClientScriptTest extends TestCase
{
    public function test_script_parses_as_valid_bash(): void
    {
        $process = new Process([
            'bash',
            '-n',
            base_path('scripts/create-client.sh'),
        ]);

        $process->mustRun();

        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_help_exposes_name_preset_and_starter_package_options(): void
    {
        $process = new Process([
            'bash',
            base_path('scripts/create-client.sh'),
            '--help',
        ]);

        $process->mustRun();

        $output = $process->getOutput();

        $this->assertStringContainsString('--name "Client Name"', $output);
        $this->assertStringContainsString('--preset PRESET', $output);
        $this->assertStringContainsString('--starter-package PACKAGE', $output);
        $this->assertStringContainsString('basic', $output);
        $this->assertStringContainsString('artist', $output);
        $this->assertStringContainsString('audience', $output);
        $this->assertStringContainsString('management', $output);
        $this->assertStringContainsString('full', $output);
    }

    public function test_client_creation_bootstraps_a_private_github_repository(): void
    {
        $script = (string) file_get_contents(base_path('scripts/create-client.sh'));

        $this->assertStringContainsString(
            'ENGAGE_CORE_GITHUB_OWNER:-ImagineSocialGit',
            $script,
        );
        $this->assertStringContainsString(
            'gh auth status --hostname github.com',
            $script,
        );
        $this->assertStringContainsString(
            'gh repo view "$GITHUB_REPOSITORY" --json nameWithOwner',
            $script,
        );
        $this->assertStringContainsString(
            'gh repo create "$GITHUB_REPOSITORY" --private',
            $script,
        );
        $this->assertStringContainsString(
            'git -C "$TEMP_CLIENT_DIR" init -q',
            $script,
        );
        $this->assertStringContainsString(
            'git -C "$TEMP_CLIENT_DIR" branch -M main',
            $script,
        );
        $this->assertStringContainsString(
            'git -C "$TEMP_CLIENT_DIR" remote add origin "$GITHUB_SSH_URL"',
            $script,
        );
        $this->assertStringContainsString(
            'git -C "$TEMP_CLIENT_DIR" push -u origin main',
            $script,
        );
        $this->assertStringContainsString(
            'git@github.com:${GITHUB_REPOSITORY}.git',
            $script,
        );

        $remoteCreate = strpos(
            $script,
            'gh repo create "$GITHUB_REPOSITORY" --private',
        );
        $finalPublish = strrpos(
            $script,
            'mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"',
        );

        $this->assertNotFalse($remoteCreate);
        $this->assertNotFalse($finalPublish);
        $this->assertLessThan($finalPublish, $remoteCreate);
    }

    public function test_client_creation_uses_template_backed_presets_before_initial_commit(): void
    {
        $script = (string) file_get_contents(base_path('scripts/create-client.sh'));

        $this->assertStringContainsString('--preset)', $script);
        $this->assertStringContainsString('--starter-package)', $script);
        $this->assertStringContainsString(
            'PRESET_TEMPLATE_DIR="$PRESET_TEMPLATES_DIR/$CLIENT_PRESET"',
            $script,
        );
        $this->assertStringContainsString(
            'STARTER_PACKAGE_RESOLVER="$ROOT_DIR/scripts/resolve-client-starter-package.php"',
            $script,
        );
        $this->assertStringContainsString(
            'cp -R "$PRESET_TEMPLATE_DIR/." "$TEMP_CLIENT_DIR/"',
            $script,
        );
        $this->assertStringContainsString(
            'CLIENT_RUNTIME_PRESET_PHP="$(php -r',
            $script,
        );
        $this->assertStringContainsString(
            "'preset' => \$CLIENT_RUNTIME_PRESET_PHP",
            $script,
        );

        $presetCopy = strpos(
            $script,
            'cp -R "$PRESET_TEMPLATE_DIR/." "$TEMP_CLIENT_DIR/"',
        );
        $packageResolution = strpos(
            $script,
            'php "$STARTER_PACKAGE_RESOLVER"',
        );
        $initialCommit = strpos(
            $script,
            'git -C "$TEMP_CLIENT_DIR" commit -q -m "feat: initialize client"',
        );

        $this->assertNotFalse($presetCopy);
        $this->assertNotFalse($packageResolution);
        $this->assertNotFalse($initialCommit);
        $this->assertLessThan($packageResolution, $presetCopy);
        $this->assertLessThan($initialCommit, $packageResolution);
    }

    public function test_artist_preset_matches_the_canonical_artist_audience_package(): void
    {
        $modules = require base_path(
            'docs/config-templates/client-presets/artist/config/modules.php',
        );
        $starterPackages = require base_path(
            'docs/config-templates/client-starter-packages/artist.php',
        );
        $messaging = require base_path(
            'docs/config-templates/client-presets/artist/config/messaging.php',
        );
        $presets = require base_path(
            'docs/config-templates/client-presets/artist/config/presets.php',
        );
        $forms = require base_path(
            'docs/config-templates/client-presets/artist/config/presets/modules/forms/forms.php',
        );

        $this->assertSame([
            'messaging',
            'inbound_messaging',
            'broadcasts',
            'campaigns',
            'forms',
            'media',
            'integrations',
            'reporting',
        ], $modules['enabled']);

        $this->assertSame('audience', $starterPackages['default']);
        $this->assertSame('artist', $starterPackages['packages']['audience']['preset']);
        $this->assertArrayNotHasKey('modules', $starterPackages['packages']['audience']);
        $this->assertSame([
            'tasks',
            'workflow',
            'flow_routes',
            'relationships',
        ], $starterPackages['packages']['management']['modules']);
        $this->assertSame(
            'artist_management',
            $starterPackages['packages']['management']['preset'],
        );
        $this->assertSame(
            ['audience', 'management'],
            $starterPackages['packages']['full']['includes'],
        );
        $this->assertSame('artist_full', $starterPackages['packages']['full']['preset']);

        $this->assertSame(
            'marketing',
            $messaging['consent']['channel_purpose_domains']['email']['marketing'],
        );
        $this->assertSame(
            'marketing',
            $messaging['consent']['channel_purpose_domains']['sms']['marketing'],
        );

        $this->assertSame(
            ['artist_updates'],
            $presets['packages']['artist']['groups']['forms'],
        );
        $this->assertSame(
            [],
            $presets['packages']['artist_management']['groups']['forms'],
        );
        $this->assertSame(
            ['artist_updates'],
            $presets['packages']['artist_full']['groups']['forms'],
        );

        $this->assertSame(
            true,
            $forms['definitions']['artist_updates']['settings']['submission']['verification']['required'],
        );
    }

    public function test_artist_starter_packages_resolve_explicit_module_and_runtime_preset_choices(): void
    {
        $audience = $this->resolveStarterPackage('artist', '');
        $management = $this->resolveStarterPackage('artist', 'management');
        $full = $this->resolveStarterPackage('artist', 'full');

        $this->assertSame('audience', $audience['starter_package']);
        $this->assertSame('Artist Audience', $audience['starter_package_name']);
        $this->assertSame('artist', $audience['runtime_preset']);
        $this->assertSame([
            'messaging',
            'inbound_messaging',
            'broadcasts',
            'campaigns',
            'forms',
            'media',
            'integrations',
            'reporting',
        ], $audience['modules']);

        $this->assertSame('artist_management', $management['runtime_preset']);
        $this->assertSame([
            'tasks',
            'workflow',
            'flow_routes',
            'relationships',
        ], $management['modules']);

        $this->assertSame('artist_full', $full['runtime_preset']);
        $this->assertSame([
            'messaging',
            'inbound_messaging',
            'broadcasts',
            'campaigns',
            'forms',
            'media',
            'integrations',
            'reporting',
            'tasks',
            'workflow',
            'flow_routes',
            'relationships',
        ], $full['modules']);
    }

    public function test_starter_package_is_rejected_for_a_preset_without_package_choices(): void
    {
        $process = new Process([
            'php',
            base_path('scripts/resolve-client-starter-package.php'),
            base_path('docs/config-templates/client-presets/basic'),
            base_path('docs/config-templates/client-starter-packages/basic.php'),
            'full',
        ]);

        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'does not define starter packages',
            $process->getErrorOutput(),
        );
    }

    public function test_basic_preset_remains_the_default_module_package(): void
    {
        $modules = require base_path(
            'docs/config-templates/client-presets/basic/config/modules.php',
        );

        $this->assertSame([
            'tasks',
            'workflow',
        ], $modules['enabled']);

        $script = (string) file_get_contents(base_path('scripts/create-client.sh'));

        $this->assertStringContainsString(
            'CLIENT_PRESET="basic"',
            $script,
        );
    }

    public function test_client_creation_supports_an_explicit_display_name_and_ignores_runtime_env_files(): void
    {
        $script = (string) file_get_contents(base_path('scripts/create-client.sh'));

        $this->assertStringContainsString('--name)', $script);
        $this->assertStringContainsString(
            'CLIENT_NAME_PHP="$(php -r',
            $script,
        );
        $this->assertStringContainsString(
            "cat > \"\$TEMP_CLIENT_DIR/.gitignore\" <<'EOF_GITIGNORE'",
            $script,
        );
        $this->assertStringContainsString(
            ".env\n.env.*\n!.env.example",
            $script,
        );
    }

    /** @return array<string, mixed> */
    private function resolveStarterPackage(string $preset, string $package): array
    {
        $process = new Process([
            'php',
            base_path('scripts/resolve-client-starter-package.php'),
            base_path('docs/config-templates/client-presets/'.$preset),
            base_path('docs/config-templates/client-starter-packages/'.$preset.'.php'),
            $package,
        ]);

        $process->mustRun();

        $resolved = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($resolved);

        return $resolved;
    }
}