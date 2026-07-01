<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;

class MessageChannelAvailability
{
    /**
     * @return array<int, string>
     */
    public function visibleChannelsForSurface(
        string $surface,
        ?string $purpose = null,
        ?string $scope = null,
        bool $requireProvider = false,
    ): array {
        $channels = [];

        foreach (MessageChannel::cases() as $channel) {
            if ($this->isVisibleForSurface(
                channel: $channel->value,
                surface: $surface,
                purpose: $purpose,
                scope: $scope,
                requireProvider: $requireProvider,
            )) {
                $channels[] = $channel->value;
            }
        }

        return $channels;
    }

    public function isVisibleForSurface(
        string|MessageChannel $channel,
        string $surface,
        ?string $purpose = null,
        ?string $scope = null,
        bool $requireProvider = false,
    ): bool {
        $channel = $this->normalizeChannel($channel);

        if (! $this->isRuntimeSupported($channel)) {
            return false;
        }

        if ($requireProvider && ! $this->isProviderEnabled($channel)) {
            return false;
        }

        if (! $this->surfaceVisible($channel, $surface)) {
            return false;
        }

        return $this->purposeScopeAllowed($channel, $purpose, $scope);
    }

    public function isRuntimeSupported(string|MessageChannel $channel): bool
    {
        $channel = $this->normalizeChannel($channel);

        return (bool) config("messaging.channel_availability.{$channel}.runtime_supported", false);
    }

    public function isProviderEnabled(string|MessageChannel $channel): bool
    {
        $channel = $this->normalizeChannel($channel);

        return (bool) config("messaging.channel_availability.{$channel}.provider_enabled", false);
    }

    public function requiresExplicitOptIn(string|MessageChannel $channel): bool
    {
        $channel = $this->normalizeChannel($channel);

        return (bool) config("messaging.channel_availability.{$channel}.requires_explicit_opt_in", false);
    }

    /**
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    public function normalizeVisibleChannelsForSurface(
        array $channels,
        string $surface,
        ?string $purpose = null,
        ?string $scope = null,
        bool $requireProvider = false,
    ): array {
        $visible = $this->visibleChannelsForSurface(
            surface: $surface,
            purpose: $purpose,
            scope: $scope,
            requireProvider: $requireProvider,
        );

        return array_values(array_intersect($visible, array_values(array_unique(array_map(
            fn (string $channel): string => $this->normalizeChannel($channel),
            $channels,
        )))));
    }

    private function surfaceVisible(string $channel, string $surface): bool
    {
        return (bool) config("messaging.channel_availability.{$channel}.surfaces.{$surface}", false);
    }

    private function purposeScopeAllowed(string $channel, ?string $purpose, ?string $scope): bool
    {
        $rules = config("messaging.channel_availability.{$channel}.purpose_scopes", ['*' => true]);

        if (! is_array($rules)) {
            return false;
        }

        if ((bool) ($rules['*'] ?? false)) {
            return true;
        }

        if ($purpose === null || $scope === null) {
            return false;
        }

        $purpose = $this->normalizeKey($purpose);
        $scope = $this->normalizeKey($scope);

        return (bool) ($rules["{$purpose}:{$scope}"] ?? false)
            || (bool) ($rules["{$purpose}:*"] ?? false);
    }

    private function normalizeChannel(string|MessageChannel $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : $this->normalizeKey($channel);
    }

    private function normalizeKey(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
