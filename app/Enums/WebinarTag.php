<?php

namespace App\Enums;

use InvalidArgumentException;

enum WebinarTag: string
{
    case Homebuyer = 'homebuyer';
    case Va = 'va';
    case Build = 'build';
    case Bridge = 'bridge';
    case Relocation = 'relocation';

    public function label(): string
    {
        return match ($this) {
            self::Homebuyer => 'Homebuyer',
            self::Va => 'VA',
            self::Build => 'Build',
            self::Bridge => 'Bridge',
            self::Relocation => 'Relocation',
        };
    }

    public static function fromWebinarSlug(string $slug): self
    {
        return match ($slug) {
            'homebuyer-game-plan' => self::Homebuyer,
            'va-game-plan' => self::Va,
            'build-game-plan' => self::Build,
            'bridge-game-plan' => self::Bridge,
            'relocation-game-plan' => self::Relocation,
            default => throw new InvalidArgumentException("Unsupported webinar slug [{$slug}]"),
        };
    }
}