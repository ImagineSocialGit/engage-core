<?php

namespace App\Support\Clients;

final class ClientPackageRuntime
{
    private static ?ClientPackageManifest $manifest = null;

    public static function manifest(): ClientPackageManifest
    {
        return self::$manifest ?? ClientPackageManifest::empty();
    }

    public static function replace(ClientPackageManifest $manifest): void
    {
        self::$manifest = $manifest;
    }

    public static function reset(): void
    {
        self::$manifest = null;
    }
}