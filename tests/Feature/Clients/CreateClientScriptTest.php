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