<?php

namespace App\Modules\Broadcasts\Services;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class BroadcastMessageTemplateVersionService
{
    public const SOURCE = 'broadcast_private';
    public const SOURCE_VERSION = '2';

    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
    ) {}

    /**
     * Persist the editable Broadcast copy as the current immutable version of
     * one private Messaging template. Editing a draft clears any pinned version;
     * scheduling pins the exact current version separately.
     *
     * @param array<string, mixed> $payload
     */
    public function saveDraft(
        Broadcast $broadcast,
        array $payload,
        ?User $createdBy = null,
    ): MessageTemplateVersion {
        $this->assertPersisted($broadcast);

        return DB::transaction(function () use ($broadcast, $payload, $createdBy): MessageTemplateVersion {
            $locked = Broadcast::query()
                ->whereKey($broadcast->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== Broadcast::STATUS_DRAFT) {
                throw new InvalidArgumentException(
                    'Only draft Broadcasts can change their message copy.',
                );
            }

            $template = $this->resolveOrCreateTemplate($locked);

            $template->forceFill([
                'name' => $this->templateName($locked),
                'description' => 'Private authored message for Broadcast #'.$locked->getKey().'.',
                'channel' => (string) $locked->channel,
                'status' => MessageTemplate::STATUS_ACTIVE,
                'composition_context_key' => null,
                'composition_family_key' => null,
                'source' => self::SOURCE,
                'source_version' => self::SOURCE_VERSION,
                'is_customized' => false,
                'customized_at' => null,
            ])->save();

            $version = $this->publishMessageTemplateVersion->handle(
                messageTemplate: $template,
                payload: $payload,
                createdBy: $createdBy,
                rendererVersion: '1',
                resolveComposition: false,
            );

            $locked->forceFill([
                'message_template_id' => $template->getKey(),
                'message_template_version_id' => null,
            ])->save();

            $broadcast->setRawAttributes($locked->getAttributes(), true);
            $broadcast->setRelation('messageTemplate', $template);
            $broadcast->unsetRelation('messageTemplateVersion');

            return $version;
        }, 3);
    }

    public function pin(Broadcast $broadcast): MessageTemplateVersion
    {
        $this->assertPersisted($broadcast);

        $template = $this->resolveTemplate($broadcast);
        $this->assertCompatible($template, $broadcast);
        $version = $template->requireCurrentVersion();

        $broadcast->forceFill([
            'message_template_id' => $template->getKey(),
            'message_template_version_id' => $version->getKey(),
        ])->save();
        $broadcast->setRelation('messageTemplate', $template);
        $broadcast->setRelation('messageTemplateVersion', $version);

        return $version;
    }

    public function resolvePinned(Broadcast $broadcast): MessageTemplateVersion
    {
        $this->assertPersisted($broadcast);

        if (! is_numeric($broadcast->message_template_id)
            || ! is_numeric($broadcast->message_template_version_id)
        ) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] has no pinned Messaging template version.',
            );
        }

        $version = MessageTemplateVersion::query()
            ->with('messageTemplate')
            ->find($broadcast->message_template_version_id);

        if (! $version instanceof MessageTemplateVersion
            || (int) $version->message_template_id !== (int) $broadcast->message_template_id
        ) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] pinned Messaging version is invalid.',
            );
        }

        $template = $version->messageTemplate;

        if (! $template instanceof MessageTemplate) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] pinned Messaging template is missing.',
            );
        }

        $this->assertCompatible($template, $broadcast);

        return $version;
    }

    public function templateKey(Broadcast $broadcast): string
    {
        $this->assertPersisted($broadcast);

        return 'broadcast.private.'.(int) $broadcast->getKey();
    }

    private function resolveOrCreateTemplate(Broadcast $broadcast): MessageTemplate
    {
        if (is_numeric($broadcast->message_template_id)) {
            $template = MessageTemplate::query()->find($broadcast->message_template_id);

            if (! $template instanceof MessageTemplate) {
                throw new RuntimeException(
                    'Broadcast ['.$broadcast->getKey().'] private Messaging template is missing.',
                );
            }

            $this->assertOwned($template, $broadcast);

            return $template;
        }

        $template = MessageTemplate::query()->firstOrCreate(
            ['key' => $this->templateKey($broadcast)],
            [
                'name' => $this->templateName($broadcast),
                'description' => 'Private authored message for Broadcast #'.$broadcast->getKey().'.',
                'channel' => (string) $broadcast->channel,
                'status' => MessageTemplate::STATUS_ACTIVE,
                'composition_context_key' => null,
                'composition_family_key' => null,
                'source' => self::SOURCE,
                'source_version' => self::SOURCE_VERSION,
                'is_customized' => false,
                'customized_at' => null,
            ],
        );

        $this->assertOwned($template, $broadcast);

        return $template;
    }

    private function resolveTemplate(Broadcast $broadcast): MessageTemplate
    {
        if (! is_numeric($broadcast->message_template_id)) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] has no private Messaging template.',
            );
        }

        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->find($broadcast->message_template_id);

        if (! $template instanceof MessageTemplate) {
            throw new RuntimeException(
                'Broadcast ['.$broadcast->getKey().'] private Messaging template is missing.',
            );
        }

        $this->assertOwned($template, $broadcast);

        return $template;
    }

    private function templateName(Broadcast $broadcast): string
    {
        return Str::limit(
            'Broadcast #'.$broadcast->getKey().': '.$broadcast->name,
            191,
            '',
        );
    }

    private function assertPersisted(Broadcast $broadcast): void
    {
        if (! $broadcast->exists || ! $broadcast->getKey()) {
            throw new RuntimeException(
                'Broadcast must be persisted before its private message can be stored.',
            );
        }
    }

    private function assertOwned(
        MessageTemplate $template,
        Broadcast $broadcast,
    ): void {
        if ($template->key !== $this->templateKey($broadcast)
            || $template->source !== self::SOURCE
        ) {
            throw new RuntimeException(
                'Broadcast private template is owned by another source or Broadcast.',
            );
        }
    }

    private function assertCompatible(
        MessageTemplate $template,
        Broadcast $broadcast,
    ): void {
        $this->assertOwned($template, $broadcast);

        if ($template->channel !== $broadcast->channel) {
            throw new RuntimeException(
                'Broadcast private template channel does not match the Broadcast channel.',
            );
        }
    }
}