<?php

namespace App\Modules\Location\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Location\Models\ContactLocation;
use App\Modules\Location\Models\Location;

/**
 * Projects an imported country/region assertion into an explicitly unverified
 * Location estimate. Never makes it an address or a primary contact location.
 */
final class ImportContactGeographyHandler implements ContactImportHandler
{
    private const IMPORT_SOURCE = 'external_geo_estimate';

    public function handle(ContactImportContext $context): void
    {
        $countryRaw = $context->value('country_code');
        $regionRaw = $context->value('region_code');

        if ($countryRaw === null && $regionRaw === null) {
            return;
        }

        $country = $countryRaw !== null ? strtoupper(trim($countryRaw)) : null;
        $region = $regionRaw !== null ? strtoupper(trim($regionRaw)) : null;
        $rejected = [];

        if ($country === null || preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            $rejected['country_code'] = $countryRaw === null
                ? 'A country code is required for geographic projection.'
                : $countryRaw;
            $this->recordOccurrence($context, null, null, $rejected);

            return;
        }

        if ($region !== null && preg_match('/\A[A-Z0-9_-]{1,32}\z/', $region) !== 1) {
            $rejected['region_code'] = $regionRaw;
            $region = null;
        }

        // Geographic identity is shared by all contacts in the same broad area.
        // Provenance belongs on the individual contact link / import occurrence.
        $key = 'import_geo:'.$country.':'.($region ?? '_');
        $location = Location::withTrashed()->firstOrCreate(
            ['key' => $key],
            [
                'name' => $region !== null ? $region.', '.$country : $country,
                'label' => 'Approximate geography',
                'type' => Location::TYPE_REGION,
                'status' => Location::STATUS_ACTIVE,
                'region' => $region,
                'country' => $country,
                'precision' => $region !== null ? 'region' : 'country',
                'source' => self::IMPORT_SOURCE,
                'meta' => ['estimated' => true],
            ],
        );

        if ($location->trashed()) {
            $location->restore();
        }

        // The only relationship we update is one this importer itself owns.
        // Verified home/work/billing/shipping records are never overwritten.
        $link = ContactLocation::withTrashed()->firstOrNew([
            'contact_id' => $context->contact->getKey(),
            'type' => ContactLocation::TYPE_OTHER,
            'source' => self::IMPORT_SOURCE,
        ]);

        if ($link->exists && $link->trashed()) {
            $link->restore();
        }

        $link->forceFill([
            'location_id' => $location->getKey(),
            'subject_type' => $context->contact->getMorphClass(),
            'subject_id' => $context->contact->getKey(),
            'label' => 'Imported geographic estimate',
            'status' => ContactLocation::STATUS_ACTIVE,
            'is_primary' => false,
            'verified_at' => null,
            'meta' => [
                'estimated' => true,
                'import_batch_id' => $context->batch->getKey(),
                'import_occurrence_id' => $context->occurrence->getKey(),
                'profile_key' => $context->profileKey,
            ],
        ])->save();

        $this->recordOccurrence($context, $location->getKey(), $link->getKey(), $rejected);
    }

    /** @param array<string, mixed> $rejected */
    private function recordOccurrence(
        ContactImportContext $context,
        ?int $locationId,
        ?int $contactLocationId,
        array $rejected,
    ): void {
        $meta = is_array($context->occurrence->meta) ? $context->occurrence->meta : [];
        $meta['imported_geography'] = [
            'source' => self::IMPORT_SOURCE,
            'location_id' => $locationId,
            'contact_location_id' => $contactLocationId,
            'rejected' => $rejected,
        ];

        $context->occurrence->forceFill(['meta' => $meta])->save();
    }
}