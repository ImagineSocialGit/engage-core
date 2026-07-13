<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Support\Carbon;

class ImportMessageConsentAction
{
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
        $consent = MessageConsent::query()->firstOrNew([
            'contact_id' => $contact->getKey(),
            'channel' => $this->normalizeSegment($channel),
            'purpose' => $this->normalizeSegment($purpose),
            'scope' => $this->normalizeSegment($scope),
        ]);

        $created = ! $consent->exists;

        $consent->forceFill([
            'consented_at' => $consentedAt ? Carbon::parse($consentedAt) : now(),
            'source' => trim($source) !== '' ? trim($source) : 'import',
            'meta' => array_replace_recursive(
                is_array($consent->meta) ? $consent->meta : [],
                $meta,
            ),
        ])->save();

        return [
            'consent' => $consent->refresh(),
            'created' => $created,
        ];
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
