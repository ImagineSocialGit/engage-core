<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Events\MessageConsentGranted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantMessageConsentAction
{
    public function __construct(
        private readonly RecordMessageConsentAction $recordMessageConsent,
    ) {}

    /**
     * Grant consent and emit the domain event when consent becomes active.
     *
     * Message acknowledgement delivery is intentionally caller-owned.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public function handle(
        Contact $contact,
        array $data,
        ?Model $context = null,
    ): MessageConsentGrantResult {
        $result = $this->recordMessageConsent->handle($contact, $data);

        if (! $result->becameActive) {
            return $result;
        }

        DB::afterCommit(function () use ($contact, $result, $context): void {
            MessageConsentGranted::dispatch(
                contact: $contact,
                messageConsent: $result->consent,
                channel: $result->channel,
                purpose: $result->purpose,
                scope: $result->domain,
                context: $context,
                data: [
                    'source' => $result->consent->source,
                    'ip_address' => $result->consent->ip_address,
                    'user_agent' => $result->consent->user_agent,
                    'meta' => $result->consent->meta,
                    'requested_scope' => $result->requestedScope,
                    'domain' => $result->domain,
                ],
            );
        });

        return $result;
    }
}
