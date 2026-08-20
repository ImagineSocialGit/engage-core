<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Services\MessageTemplateCompositionResolver;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class PublishMessageTemplateVersionAction
{
    public function __construct(
        private readonly MessageTemplateCompositionResolver $compositionResolver,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        MessageTemplate $messageTemplate,
        array $payload,
        ?User $createdBy = null,
        string $rendererVersion = '1',
    ): MessageTemplateVersion {
        return DB::transaction(function () use (
            $messageTemplate,
            $payload,
            $createdBy,
            $rendererVersion,
        ): MessageTemplateVersion {
            $template = MessageTemplate::query()
                ->whereKey($messageTemplate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payload = $this->compositionResolver->resolve(
                messageTemplate: $template,
                sourcePayload: $payload,
            );

            $rendererKey = $this->rendererKey($template);
            $subject = $this->subject($payload);
            $content = $this->content($payload);
            $contentHash = $this->contentHash(
                subject: $subject,
                content: $content,
                rendererKey: $rendererKey,
                rendererVersion: $rendererVersion,
            );

            $version = MessageTemplateVersion::query()
                ->where('message_template_id', $template->getKey())
                ->where('content_hash', $contentHash)
                ->first();

            if (! $version instanceof MessageTemplateVersion) {
                $version = MessageTemplateVersion::query()->create([
                    'message_template_id' => $template->getKey(),
                    'version' => $this->nextVersion($template),
                    'subject' => $subject,
                    'content' => $content,
                    'renderer_key' => $rendererKey,
                    'renderer_version' => $rendererVersion,
                    'content_hash' => $contentHash,
                    'created_by' => $createdBy?->getKey(),
                ]);
            }

            if ((int) $template->current_version_id !== (int) $version->getKey()) {
                $template->forceFill([
                    'current_version_id' => $version->getKey(),
                ])->save();
            }

            $messageTemplate->setRawAttributes($template->getAttributes(), true);
            $messageTemplate->setRelation('currentVersion', $version);

            return $version;
        }, 3);
    }

    private function rendererKey(MessageTemplate $messageTemplate): string
    {
        $channel = trim((string) $messageTemplate->channel);

        if ($channel === '') {
            throw new RuntimeException(
                'MessageTemplate channel is required before publishing a version.',
            );
        }

        return $channel;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function subject(array $payload): ?string
    {
        $subject = $payload['subject'] ?? null;

        if (! is_string($subject)) {
            return null;
        }

        $subject = trim($subject);

        return $subject !== '' ? $subject : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function content(array $payload): array
    {
        unset($payload['subject']);

        return $this->normalizeAssociativeArray($payload);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function contentHash(
        ?string $subject,
        array $content,
        string $rendererKey,
        string $rendererVersion,
    ): string {
        try {
            $encoded = json_encode([
                'renderer_key' => $rendererKey,
                'renderer_version' => $rendererVersion,
                'subject' => $subject,
                'content' => $content,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'MessageTemplateVersion content could not be encoded.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    private function nextVersion(MessageTemplate $messageTemplate): int
    {
        $currentMaximum = MessageTemplateVersion::query()
            ->where('message_template_id', $messageTemplate->getKey())
            ->max('version');

        return ((int) $currentMaximum) + 1;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeAssociativeArray(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeAssociativeArray($value);
            }
        }

        return $values;
    }
}