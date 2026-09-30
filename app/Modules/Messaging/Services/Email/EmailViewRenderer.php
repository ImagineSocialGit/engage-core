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
                $this->coreViewPath($view),
                $data,
            )->render(),
            default => throw new InvalidArgumentException(
                "Unsupported email presentation [{$presentation}].",
            ),
        };
    }

    private function coreViewPath(string $view): string
    {
        $view = trim($view);

        if ($view === ''
            || str_contains($view, '::')
            || str_contains($view, '..')
            || str_contains($view, '/')
            || str_contains($view, '\\')
        ) {
            throw new InvalidArgumentException(
                "Standard email presentation requires a Core semantic view name; [{$view}] is invalid.",
            );
        }

        $path = resource_path(
            'views/'.str_replace('.', DIRECTORY_SEPARATOR, $view).'.blade.php',
        );

        if (! is_file($path)) {
            throw new InvalidArgumentException(
                "Standard email view [{$view}] does not exist in Core resources/views.",
            );
        }

        return $path;
    }
}