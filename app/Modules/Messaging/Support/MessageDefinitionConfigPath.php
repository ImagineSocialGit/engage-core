<?php

namespace App\Modules\Messaging\Support;

final class MessageDefinitionConfigPath
{
    public static function definitionsRoot(string $channel): string
    {
        return 'messaging.'.self::segment($channel).'.definitions';
    }

    public static function purpose(string $channel, string $purpose): string
    {
        return self::definitionsRoot($channel).'.'.self::segment($purpose);
    }

    public static function scope(string $channel, string $purpose, string $scope): string
    {
        return self::purpose($channel, $purpose).'.'.self::segment($scope);
    }

    public static function templateSet(
        string $channel,
        string $purpose,
        string $scope,
        string $templateSetKey,
    ): string {
        return self::scope($channel, $purpose, $scope)
            .'.'.trim($templateSetKey);
    }

    public static function definition(
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        int|string|null $index = null,
        ?string $templateSetKey = null,
    ): string {
        $root = $templateSetKey === null
            ? self::scope($channel, $purpose, $scope)
            : self::templateSet(
                $channel,
                $purpose,
                $scope,
                $templateSetKey,
            );
        $path = $root.'.'.self::segment($messageType);

        return $index === null ? $path : $path.'.'.$index;
    }

    public static function campaignVariant(
        string $channel,
        string $purpose,
        string $scope,
        string $campaignKey,
        int $stepNumber,
        string $variantKey,
    ): string {
        return implode('.', [
            self::scope($channel, $purpose, $scope),
            'campaigns',
            self::segment($campaignKey),
            'steps',
            (string) $stepNumber,
            'variants',
            self::segment($variantKey),
        ]);
    }

    public static function payloadField(
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        string $field,
    ): string {
        return self::definition($channel, $purpose, $scope, $messageType)
            .'.payload.'.self::segment($field);
    }

    private static function segment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}