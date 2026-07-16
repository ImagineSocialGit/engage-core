<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Support\Carbon;

class ImportMessageConsentAction
{
    public function __construct(
        private readonly RecordMessageConsentAction $recordMessageConsent,
    ) {}

    /**
     * Import consent state without dispatching consent events or opt-in messages.
     *
     * @param array<string, mixed> $meta
     * @return array{consent: MessageConsent, created: bool}
     */
    public function handle(
        Contact $contact,
        string $channel,
        string $purpose,
        string $scope,
        Carbon|string|null $consentedAt = null,
        string $source = 'import',
        array $meta = [],
    ): array {
        $result = $this->recordMessageConsent->handle($contact, [
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
            'consented_at' => $consentedAt ? Carbon::parse($consentedAt) : now(),
            'source' => trim($source) !== '' ? trim($source) : 'import',
            'meta' => $meta,
        ]);

        return [
            'consent' => $result->consent->refresh(),
            'created' => $result->created,
        ];
    }
}
