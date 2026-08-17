<?php

namespace App\Modules\Forms\Controllers\External;

use App\Modules\Forms\Data\ExternalFormIntakeClient;
use App\Modules\Forms\Data\ExternalPublishedForm;
use App\Modules\Forms\Http\Middleware\AuthenticateExternalFormIntake;
use App\Modules\Forms\Services\PublishedFormResolver;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class ExternalPublishedFormController
{
    public function __construct(
        private readonly PublishedFormResolver $forms,
    ) {}

    public function __invoke(Request $request, string $form): JsonResponse
    {
        $client = $request->attributes->get(
            AuthenticateExternalFormIntake::CLIENT_ATTRIBUTE,
        );

        if (! $client instanceof ExternalFormIntakeClient) {
            Log::error('Authenticated external Forms client is missing from the published-form request.');

            return $this->error(
                request: $request,
                status: 503,
                code: 'external_intake_unavailable',
                message: 'External form access is temporarily unavailable.',
            );
        }

        try {
            $published = $this->forms->require(
                key: $form,
                publicOnly: true,
            );
        } catch (DomainException $exception) {
            Log::warning('External Forms client could not resolve a usable published form.', [
                'form_key' => $form,
                'client_id' => $client->id,
                'exception' => $exception,
            ]);

            return $this->error(
                request: $request,
                status: 503,
                code: 'form_unavailable',
                message: 'The requested form is temporarily unavailable.',
            );
        }

        return response()->json([
            'data' => ExternalPublishedForm::fromPublishedForm($published)->toArray(),
            'request_id' => $request->attributes->get('request_id'),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function error(
        Request $request,
        int $status,
        string $code,
        string $message,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $status)->header('Cache-Control', 'private, no-store');
    }
}