<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Services\MessageTemplateCompositionConfigRegistry;
use Illuminate\Support\Facades\DB;

class SyncMessageTemplateCompositionLayersAction
{
    public function __construct(
        private readonly MessageTemplateCompositionConfigRegistry $configRegistry,
        private readonly UpsertMessageTemplateCompositionLayerAction $upsertLayer,
    ) {}

    /**
     * @return array{created: int, updated: int, customized_skipped: int, stale_removed: int}
     */
    public function handle(bool $force = false): array
    {
        $clientKey = $this->configRegistry->clientKey();
        $definitions = $this->configRegistry->definitions($clientKey);

        return DB::transaction(function () use ($definitions, $clientKey, $force): array {
            $result = [
                'created' => 0,
                'updated' => 0,
                'customized_skipped' => 0,
                'stale_removed' => 0,
            ];
            $activeIdentityKeys = [];

            foreach ($definitions as $definition) {
                $existing = $this->matchingLayer($definition);

                if ($existing instanceof MessageTemplateCompositionLayer
                    && $existing->is_customized
                    && ! $force
                ) {
                    $activeIdentityKeys[] = $existing->identity_key;
                    $result['customized_skipped']++;

                    continue;
                }

                $layer = $this->upsertLayer->handle(
                    scopeType: $definition['scope_type'],
                    channel: $definition['channel'],
                    payload: $definition['payload'],
                    clientKey: $definition['client_key'],
                    contextKey: $definition['context_key'],
                    familyKey: $definition['family_key'],
                    source: 'config',
                    sourceVersion: $definition['source_version'],
                    isCustomized: false,
                );

                $activeIdentityKeys[] = $layer->identity_key;
                $result[$layer->wasRecentlyCreated ? 'created' : 'updated']++;
            }

            $stale = MessageTemplateCompositionLayer::query()
                ->where('source', 'config')
                ->where('is_customized', false)
                ->whereNull('message_template_id');

            if ($clientKey === null) {
                $stale->whereNull('client_key');
            } else {
                $stale->where(function ($query) use ($clientKey): void {
                    $query
                        ->whereNull('client_key')
                        ->orWhere('client_key', $clientKey);
                });
            }

            if ($activeIdentityKeys !== []) {
                $stale->whereNotIn('identity_key', array_values(array_unique($activeIdentityKeys)));
            }

            $result['stale_removed'] = $stale->count();
            $stale->delete();

            return $result;
        });
    }

    /**
     * @param array{scope_type: string, channel: string, client_key: ?string, context_key: ?string, family_key: ?string} $definition
     */
    private function matchingLayer(array $definition): ?MessageTemplateCompositionLayer
    {
        $query = MessageTemplateCompositionLayer::query()
            ->where('scope_type', $definition['scope_type'])
            ->where('channel', $definition['channel'])
            ->whereNull('message_template_id');

        foreach (['client_key', 'context_key', 'family_key'] as $column) {
            $value = $definition[$column];

            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query->first();
    }
}