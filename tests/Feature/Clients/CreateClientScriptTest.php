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

    public function test_help_exposes_name_and_preset_options(): void
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
        $this->assertStringContainsString('basic', $output);
        $this->assertStringContainsString('artist', $output);
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
        $this->assertStringContainsString(
            'PRESET_TEMPLATE_DIR="$PRESET_TEMPLATES_DIR/$CLIENT_PRESET"',
            $script,
        );
        $this->assertStringContainsString(
            'cp -R "$PRESET_TEMPLATE_DIR/." "$TEMP_CLIENT_DIR/"',
            $script,
        );
        $this->assertStringContainsString(
            'CLIENT_PRESET_PHP="$(php -r',
            $script,
        );
        $this->assertStringContainsString(
            "'preset' => \$CLIENT_PRESET_PHP",
            $script,
        );

        $presetCopy = strpos(
            $script,
            'cp -R "$PRESET_TEMPLATE_DIR/." "$TEMP_CLIENT_DIR/"',
        );
        $initialCommit = strpos(
            $script,
            'git -C "$TEMP_CLIENT_DIR" commit -q -m "feat: initialize client"',
        );

        $this->assertNotFalse($presetCopy);
        $this->assertNotFalse($initialCommit);
        $this->assertLessThan($initialCommit, $presetCopy);
    }

    public function test_artist_preset_matches_the_canonical_artist_client_package(): void
    {
        $modules = require base_path(
            'docs/config-templates/client-presets/artist/config/modules.php',
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
            'broadcasts',
            'campaigns',
            'forms',
            'integrations',
            'reporting',
        ], $modules['enabled']);

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
            true,
            $forms['definitions']['artist_updates']['settings']['submission']['verification']['required'],
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
}