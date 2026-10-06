<?php

namespace App\Modules\Messaging\Services\Email;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\Clients\ViewResolver;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;

final class EmailViewRenderer
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(
        string $view,
        array $data,
        string $presentation = EmailPayload::PRESENTATION_CLIENT,
    ): string {
        return match ($presentation) {
            EmailPayload::PRESENTATION_CLIENT => View::make(
                ViewResolver::resolve($view),
                $data,
            )->render(),
            EmailPayload::PRESENTATION_STANDARD => View::file(
                $this->standardViewPath(),
                $data,
            )->render(),
            default => throw new InvalidArgumentException(
                "Unsupported email presentation [{$presentation}].",
            ),
        };
    }

    private function standardViewPath(): string
    {
        $path = resource_path('views/email-standard.blade.php');

        if (! is_file($path)) {
            throw new InvalidArgumentException(
                'Standard email view [email-standard] does not exist in Core resources/views.',
            );
        }

        return $path;
    }
}