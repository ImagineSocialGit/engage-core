<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use InvalidArgumentException;

final class CampaignEligibilityEvaluator
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
    ) {}

    public function eligible(Campaign $campaign, Contact $contact): bool
    {
        $criteria = $this->runtimeCriteria($campaign->eligibilityCriteria());
        $exclusions = $this->runtimeCriteria($campaign->eligibilityExclusions());

        if ($criteria === null || $criteria === []) {
            return false;
        }

        // Exclusions fail closed. If an installed module can no longer resolve
        // a saved exclusion, outreach must stop rather than silently include it.
        if ($exclusions === null) {
            return false;
        }

        try {
            return $this->contactFilterResolver
                ->query([
                    'type' => 'criteria',
                    'criteria' => $criteria,
                    'exclude_criteria' => $exclusions,
                ])
                ->whereKey($contact->getKey())
                ->exists();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Stored Campaign eligibility uses stable semantic values. The existing
     * Workflow status criterion predates this contract and consumes DB IDs, so
     * Campaigns translates stable ContactStatus keys at the Core boundary.
     *
     * @param array<string, array<int, string>> $criteria
     * @return array<string, array<int, string>>|null
     */
    private function runtimeCriteria(array $criteria): ?array
    {
        if ($criteria === [] || ! array_key_exists('status', $criteria)) {
            return $criteria;
        }

        $statusKeys = $this->stableStringValues($criteria['status']);

        if ($statusKeys === []) {
            return null;
        }

        $normalizedKeys = array_values(array_unique(array_map(
            fn (string $key): string => $this->normalizeSegment($key),
            $statusKeys,
        )));

        $statusIds = ContactStatus::query()
            ->active()
            ->whereIn('key', $normalizedKeys)
            ->get(['id', 'key'])
            ->keyBy('key');

        if ($statusIds->count() !== count($normalizedKeys)) {
            return null;
        }

        $criteria['status'] = array_map(
            fn (string $key): string => (string) ((int) $statusIds[$key]->getKey()),
            $normalizedKeys,
        );

        return $criteria;
    }

    /** @return array<int, string> */
    private function stableStringValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}