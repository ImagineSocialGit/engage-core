<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GrantMessageConsentsAction
{
    public function __construct(
        private readonly GrantMessageConsentAction $grantMessageConsent,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $grants
     * @return array<int, MessageConsentGrantResult>
     */
    public function handle(
        Contact $contact,
        array $grants,
        ?Model $context = null,
    ): array {
        return DB::transaction(function () use ($contact, $grants, $context): array {
            $results = [];

            foreach ($grants as $grant) {
                if (! is_array($grant)) {
                    continue;
                }

                $results[] = $this->grantMessageConsent->handle(
                    contact: $contact,
                    data: $grant,
                    context: $context,
                );
            }

            return $results;
        });
    }
}
