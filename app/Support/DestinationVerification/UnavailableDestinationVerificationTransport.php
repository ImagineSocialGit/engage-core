<?php

namespace App\Support\DestinationVerification;

use App\Support\DestinationVerification\Contracts\DestinationVerificationTransport;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class UnavailableDestinationVerificationTransport implements DestinationVerificationTransport
{
    public function availableChannels(
        string $surface,
        string $purpose,
        string $scope,
    ): array {
        return [];
    }

    public function normalizeDestination(
        string $channel,
        string $destination,
    ): ?string {
        return null;
    }

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
    ): void {
        throw new DomainException(
            'Destination verification delivery is not available.',
        );
    }
}