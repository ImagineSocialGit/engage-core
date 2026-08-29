<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Str;
use RuntimeException;

final class BroadcastMessageTemplateVersionService
{
    public const SOURCE = 'broadcast_runtime';

    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
    ) {}

    public function pin(Broadcast $broadcast): MessageTemplateVersion
    {
        $this->assertPersisted($broadcast);

        $template = MessageTemplate::query()->firstOrCreate(
            ['key' => $this->templateKey($broadcast)],
            [
                'name' => Str::limit(
                    'Broadcast #'.$broadcast->getKey().': '.$broadcast->name,
                    191,
                    '',
                ),
                'description' => 'Private immutable runtime copy for Broadcast #'.$broadcast->getKey().'.',
                'channel' => (string) $broadcast->channel,
                'status' => MessageTemplate::STATUS_ACTIVE,
                'composition_context_key' => null,
                'composition_family_key' => null,
                'source' => self::SOURCE,
                'source_version' => '1',
                'is_customized' => false,
                'customized_at' => null,
            ],
        );

        $this->assertCompatible(
            template: $template,
            broadcast: $broadcast,
        );

        return $this->publishMessageTemplateVersion->handle(
            messageTemplate: $template,
            payload: is_array($broadcast->payload) ? $broadcast->payload : [],
            rendererVersion: '1',
            resolveComposition: false,
        );
    }

    public function resolvePinned(Broadcast $broadcast): MessageTemplateVersion
    {
        $this->assertPersisted($broadcast);

        $template = MessageTemplate::query()
            ->where('key', $this->templateKey($broadcast))
            ->with('currentVersion')
            ->first();

        if (! $template instanceof MessageTemplate) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] has no pinned Messaging template.',
            );
        }

        $this->assertCompatible(
            template: $template,
            broadcast: $broadcast,
        );

        return $template->requireCurrentVersion();
    }

    private function templateKey(Broadcast $broadcast): string
    {
        return 'broadcast.runtime.'.(int) $broadcast->getKey();
    }

    private function assertPersisted(Broadcast $broadcast): void
    {
        if (! $broadcast->exists || ! $broadcast->getKey()) {
            throw new RuntimeException(
                'Broadcast must be persisted before its message version can be pinned.',
            );
        }
    }

    private function assertCompatible(
        MessageTemplate $template,
        Broadcast $broadcast,
    ): void {
        if ($template->source !== self::SOURCE) {
            throw new RuntimeException(
                'Broadcast runtime template key ['.$template->key.'] is owned by another source.',
            );
        }

        if ($template->channel !== $broadcast->channel) {
            throw new RuntimeException(
                'Broadcast runtime template channel does not match the Broadcast channel.',
            );
        }
    }
}