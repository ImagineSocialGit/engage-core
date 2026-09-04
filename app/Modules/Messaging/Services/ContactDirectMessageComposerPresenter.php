<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Str;

final class ContactDirectMessageComposerPresenter
{
    public const SURFACE = 'contact_direct_messages';

    public const SCOPE = 'contact_direct_message';

    public const MESSAGE_TYPE = 'contact_direct_message';

    public function __construct(
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly ReusableMessageTemplateCatalog $templateCatalog,
        private readonly MessageMediaAuthoringService $mediaAuthoring,
    ) {}

    /** @return array<string, mixed> */
    public function forContact(Contact $contact): array
    {
        $purposeOptionsByChannel = [];

        foreach ($this->providerReadyChannels($contact) as $channel) {
            $purposeOptions = [];

            foreach (MessagePurpose::cases() as $purpose) {
                if (! $this->messageEligibilityGate->allows(
                    contact: $contact,
                    channel: $channel,
                    purpose: $purpose->value,
                    scope: self::SCOPE,
                    messageKey: self::MESSAGE_TYPE,
                )) {
                    continue;
                }

                $purposeOptions[] = [
                    'value' => $purpose->value,
                    'label' => $purpose === MessagePurpose::Transactional
                        ? 'Personal / service'
                        : 'Marketing',
                ];
            }

            if ($purposeOptions !== []) {
                $purposeOptionsByChannel[$channel] = $purposeOptions;
            }
        }

        $channels = array_keys($purposeOptionsByChannel);
        $templates = $this->templateOptions($channels, $purposeOptionsByChannel);
        $currentSnapshots = array_values(array_filter(array_map(
            static fn (array $template): array => is_array($template['media'] ?? null)
                ? $template['media']
                : [],
            $templates,
        ), static fn (array $snapshot): bool => $snapshot !== []));

        $defaultChannel = in_array(MessageChannel::Email->value, $channels, true)
            ? MessageChannel::Email->value
            : ($channels[0] ?? null);
        $defaultPurpose = $defaultChannel !== null
            ? ($purposeOptionsByChannel[$defaultChannel][0]['value'] ?? null)
            : null;

        return [
            'available' => $channels !== [],
            'channels' => array_map(
                static fn (string $channel): array => [
                    'value' => $channel,
                    'label' => $channel === MessageChannel::Email->value ? 'Email' : 'SMS',
                ],
                $channels,
            ),
            'purposes_by_channel' => $purposeOptionsByChannel,
            'default_channel' => $defaultChannel,
            'default_purpose' => $defaultPurpose,
            'templates' => $templates,
            'media' => $this->mediaAuthoring->presentation($currentSnapshots),
            'request_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<int, string> */
    private function providerReadyChannels(Contact $contact): array
    {
        return array_values(array_filter(
            $this->channelAvailability->visibleChannelsForSurface(
                surface: self::SURFACE,
                scope: self::SCOPE,
                requireProvider: true,
            ),
            fn (string $channel): bool => $this->hasDestination($contact, $channel),
        ));
    }

    private function hasDestination(Contact $contact, string $channel): bool
    {
        $destination = $channel === MessageChannel::Sms->value
            ? $contact->phone
            : $contact->email;

        return is_string($destination) && trim($destination) !== '';
    }

    /**
     * @param array<int, string> $channels
     * @param array<string, array<int, array{value: string, label: string}>> $purposeOptionsByChannel
     * @return array<int, array<string, mixed>>
     */
    private function templateOptions(
        array $channels,
        array $purposeOptionsByChannel,
    ): array {
        if ($channels === []) {
            return [];
        }

        return $this->templateCatalog
            ->presets($channels)
            ->filter(function (MessageTemplatePreset $preset) use ($purposeOptionsByChannel): bool {
                $allowedPurposes = array_column(
                    $purposeOptionsByChannel[(string) $preset->channel] ?? [],
                    'value',
                );

                return in_array((string) $preset->purpose, $allowedPurposes, true);
            })
            ->map(function (MessageTemplatePreset $preset): ?array {
                $template = $preset->canonicalTemplate;

                if (! $template instanceof MessageTemplate || $template->currentVersion === null) {
                    return null;
                }

                $payload = $template->currentVersion->payload();
                $media = is_array($payload['media'] ?? null)
                    ? $payload['media']
                    : [];

                return [
                    'id' => (int) $preset->getKey(),
                    'name' => (string) $preset->name,
                    'channel' => (string) $preset->channel,
                    'purpose' => (string) $preset->purpose,
                    'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : '',
                    'body' => is_string($payload['body'] ?? null) ? $payload['body'] : '',
                    'message' => is_string($payload['message'] ?? null) ? $payload['message'] : '',
                    'media' => $media,
                    'media_asset_uuid' => is_string($media['asset_uuid'] ?? null)
                        ? $media['asset_uuid']
                        : '',
                    'media_poster_asset_uuid' => is_string($media['poster_asset_uuid'] ?? null)
                        ? $media['poster_asset_uuid']
                        : '',
                    'media_title' => is_string($media['title'] ?? null)
                        ? $media['title']
                        : '',
                    'label' => sprintf(
                        '%s · %s · %s',
                        (string) $preset->name,
                        ucfirst((string) $preset->channel),
                        (string) $preset->purpose === MessagePurpose::Transactional->value
                            ? 'Personal / service'
                            : 'Marketing',
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}