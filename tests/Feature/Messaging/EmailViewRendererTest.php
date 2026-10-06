<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\Email\EmailViewRenderer;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\View;
use Mockery;
use Tests\TestCase;

class EmailViewRendererTest extends TestCase
{
    public function test_standard_presentation_uses_dedicated_neutral_shell(): void
    {
        $data = [
            'body' => ['Fixture body.'],
        ];

        $renderedView = Mockery::mock(ViewContract::class);
        $renderedView
            ->shouldReceive('render')
            ->once()
            ->andReturn('rendered-standard-email');

        View::shouldReceive('file')
            ->once()
            ->with(
                resource_path('views/email-standard.blade.php'),
                $data,
            )
            ->andReturn($renderedView);

        $actual = (new EmailViewRenderer())->render(
            view: 'email',
            data: $data,
            presentation: EmailPayload::PRESENTATION_STANDARD,
        );

        $this->assertSame('rendered-standard-email', $actual);
    }
}