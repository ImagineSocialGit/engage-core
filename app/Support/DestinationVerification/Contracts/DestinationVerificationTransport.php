<?php

namespace App\Support\DestinationVerification\Contracts;

use Illuminate\Database\Eloquent\Model;

interface DestinationVerificationTransport
{
    /** @return array<int, string> */
    public function availableChannels(
        string $surface,
        string $purpose,
        string $scope,
    ): array;

    public function normalizeDestination(
        string $channel,
        string $destination,
    ): ?string;

    public function send(
        Model $recipient,
        string $surface,
        string $channel,
        string $purpose,
        string $scope,
        string $destination,
        string $code,
        string $dedupeKey,
        ?string $sourceIp = null,
    ): void;
}