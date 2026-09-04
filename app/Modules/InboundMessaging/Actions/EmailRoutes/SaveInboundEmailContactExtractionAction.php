<?php

namespace App\Modules\InboundMessaging\Actions\EmailRoutes;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Services\Email\InboundEmailContactExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveInboundEmailContactExtractionAction
{
    public function __construct(
        private readonly InboundEmailContactExtractor $extractor,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public function handle(
        InboundEmailRoute $route,
        bool $enabled,
        array $definition,
    ): InboundEmailRoute {
        $definition = $this->extractor->normalizeDefinition($definition);

        if ($enabled) {
            $errors = $this->extractor->validationErrors($definition);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return DB::transaction(function () use (
            $route,
            $enabled,
            $definition,
        ): InboundEmailRoute {
            $route = InboundEmailRoute::query()
                ->lockForUpdate()
                ->findOrFail($route->getKey());

            $route->forceFill([
                'contact_extraction_enabled' => $enabled,
                'contact_extraction_definition' => $definition,
            ])->save();

            return $route->refresh();
        }, 3);
    }
}