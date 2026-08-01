<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Facades\DB;

class EnsureConsentOptInMessageTemplateVersionAction
{
    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishVersion,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public function handle(array $definition): MessageTemplateVersion
    {
        $channel = $this->segment($definition['channel'] ?? null, 'channel');
        $purpose = $this->segment($definition['purpose'] ?? null, 'purpose');
        $scope = $this->segment($definition['scope'] ?? null, 'scope');
        $payload = is_array($definition['payload'] ?? null)
            ? $definition['payload']
            : [];
        $key = implode('.', [
            'system',
            'consent_acknowledgement',
            $channel,
            $purpose,
            $scope,
        ]);

        return DB::transaction(function () use (
            $key,
            $channel,
            $purpose,
            $scope,
            $payload,
        ): MessageTemplateVersion {
            $template = MessageTemplate::query()->firstOrCreate(
                ['key' => $key],
                [
                    'name' => sprintf(
                        'Consent acknowledgement — %s %s — %s',
                        ucfirst($channel),
                        ucfirst($purpose),
                        str_replace('_', ' ', $scope),
                    ),
                    'description' => 'Messaging-owned immutable consent acknowledgement copy.',
                    'channel' => $channel,
                    'status' => MessageTemplate::STATUS_ACTIVE,
                    'source' => 'system_config',
                    'source_version' => '1',
                    'is_customized' => false,
                    'customized_at' => null,
                ],
            );
            $template = MessageTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $template->forceFill([
                'channel' => $channel,
                'status' => MessageTemplate::STATUS_ACTIVE,
                'source' => 'system_config',
                'source_version' => '1',
            ])->save();

            return $this->publishVersion->handle(
                messageTemplate: $template,
                payload: $payload,
            );
        }, 3);
    }

    private function segment(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(
                "Consent acknowledgement definition is missing {$label}.",
            );
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }
}