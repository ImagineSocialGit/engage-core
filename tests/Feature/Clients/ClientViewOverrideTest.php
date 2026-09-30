<?php

namespace Tests\Feature\Clients;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Support\Clients\ViewResolver;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ClientViewOverrideTest extends TestCase
{
    private ?string $tempDirectory = null;

    protected function tearDown(): void
    {
        if (is_string($this->tempDirectory)) {
            $this->deleteDirectory($this->tempDirectory);
        }

        parent::tearDown();
    }

    public function test_email_payload_uses_generic_client_view_override_and_supplies_standard_messaging_slots(): void
    {
        $this->installClientEmailOverride();

        $payload = new EmailPayload(
            to: 'person@example.test',
            channel: 'email',
            purpose: 'transactional',
            scope: 'generic',
            messageType: 'example',
            subject: 'Fixture subject',
            body: "Opening\n{media}\nClosing",
            cta: [
                'label' => 'Primary',
                'url' => 'https://example.test/primary',
            ],
            ctas: [
                [
                    'label' => 'Additional',
                    'url' => 'https://example.test/additional',
                ],
            ],
            secondaryLink: [
                'label' => 'Secondary',
                'url' => 'https://example.test/secondary',
            ],
            media: [
                'asset_uuid' => '11111111-1111-4111-8111-111111111111',
                'kind' => 'image',
                'title' => 'Fixture media',
                'url' => 'https://example.test/media.webp',
                'mime_type' => 'image/webp',
                'tracking_key' => 'media_primary',
            ],
            footer: 'Fixture footer',
            unsubscribeUrl: 'https://example.test/unsubscribe',
            transactionalOptOutUrl: 'https://example.test/transactional-opt-out',
        );

        $html = $payload->html();

        $this->assertSame('client::email', ViewResolver::resolve('email'));
        $this->assertStringContainsString('data-test-client-email', $html);
        $this->assertStringContainsString('https://example.test/primary', $html);
        $this->assertStringContainsString('https://example.test/additional', $html);
        $this->assertStringContainsString('https://example.test/secondary', $html);
        $this->assertStringContainsString('https://example.test/media.webp', $html);
        $this->assertStringContainsString('https://example.test/transactional-opt-out', $html);
        $this->assertStringContainsString('https://example.test/unsubscribe', $html);
    }


    public function test_standard_email_presentation_bypasses_client_view_override(): void
    {
        $this->installClientEmailOverride();

        $payload = new EmailPayload(
            to: 'person@example.test',
            channel: 'email',
            purpose: 'transactional',
            scope: 'generic',
            messageType: 'example',
            subject: 'Standard fixture subject',
            body: 'Standard fixture body.',
            presentation: EmailPayload::PRESENTATION_STANDARD,
        );

        $html = $payload->html();

        $this->assertStringNotContainsString('data-test-client-email', $html);
        $this->assertStringContainsString('Standard fixture body.', $html);
    }

    public function test_internal_team_email_uses_standard_presentation_even_with_client_override(): void
    {
        $this->installClientEmailOverride();
        config()->set(
            'messaging.internal_notifications.email.from_address',
            'system@example.test',
        );
        config()->set(
            'messaging.internal_notifications.email.from_name',
            'System',
        );

        $payload = new InternalEmailNotificationPayload(
            to: 'team@example.test',
            channel: 'email',
            purpose: 'internal',
            scope: 'team',
            messageType: 'daily_summary',
            notificationType: null,
            subject: 'Internal fixture subject',
            body: ['Internal fixture body.'],
        );

        $html = $payload->mailable()->render();

        $this->assertStringNotContainsString('data-test-client-email', $html);
        $this->assertStringContainsString('Internal fixture body.', $html);
    }

    private function installClientEmailOverride(): void
    {
        $views = $this->makeTempDirectory();

        file_put_contents($views.'/email.blade.php', <<<'BLADE'
<!DOCTYPE html>
<html lang="en">
<body data-test-client-email>
    <h1>{{ $headline }}</h1>

    @foreach($body as $line)
        <div data-slot="body">{!! nl2br(e($line)) !!}</div>
    @endforeach

    @if(! empty($cta))
        <a data-slot="cta" href="{{ $cta['url'] }}">{{ $cta['label'] }}</a>
    @endif

    @foreach($ctas as $item)
        <a data-slot="ctas" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
    @endforeach

    @if(! empty($secondary_link))
        <a data-slot="secondary-link" href="{{ $secondary_link['url'] }}">{{ $secondary_link['label'] }}</a>
    @endif

    @if(! empty($footer))
        <div data-slot="footer">{{ $footer }}</div>
    @endif

    @if(! empty($transactionalOptOutUrl))
        <a data-slot="transactional-opt-out" href="{{ $transactionalOptOutUrl }}">opt out</a>
    @endif

    @if(! empty($unsubscribeUrl))
        <a data-slot="unsubscribe" href="{{ $unsubscribeUrl }}">unsubscribe</a>
    @endif
</body>
</html>
BLADE);

        View::prependLocation($views);
        View::replaceNamespace('client', [$views]);
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/engage-core-client-view-'.bin2hex(random_bytes(8));

        mkdir($directory, 0777, true);

        $this->tempDirectory = $directory;

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