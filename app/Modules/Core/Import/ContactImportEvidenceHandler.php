<?php

namespace App\Modules\Core\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use DateTimeZone;

/**
 * Keep import-origin evidence with the occurrence; Core owns only the reusable
 * contact timezone. Location can project geographic evidence when enabled.
 */
final class ContactImportEvidenceHandler implements ContactImportHandler
{
    public function handle(ContactImportContext $context): void
    {
        $contact = $context->contact;
        $evidence = [];
        $rejected = [];
        $timezoneApplied = false;

        $timezone = $context->value('timezone');

        if ($timezone !== null) {
            if (in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                if ($contact->timezone === null || $contact->timezone_source === 'import') {
                    $contact->timezone = $timezone;
                    $contact->timezone_source = 'import';
                    $timezoneApplied = true;
                }
            } else {
                $rejected['timezone'] = $timezone;
            }
        }

        // Preserve source assertions even for installations without Location.
        // These are not verified residence, billing or mailing addresses.
        foreach ([
            'country_code' => 'country_code_raw',
            'region_code' => 'region_code_raw',
            'external_signup_at' => 'signup_time_raw',
            'external_confirm_at' => 'confirmation_time_raw',
            'external_id' => 'external_id',
            'external_secondary_id' => 'external_secondary_id',
        ] as $mappedField => $evidenceKey) {
            $value = $context->value($mappedField);

            if ($value !== null) {
                $evidence[$evidenceKey] = $value;
            }
        }

        if ($contact->isDirty()) {
            $contact->save();
        }

        if ($evidence === [] && $rejected === [] && $timezone === null) {
            return;
        }

        $meta = is_array($context->occurrence->meta) ? $context->occurrence->meta : [];
        $meta['import_evidence'] = [
            'profile_key' => $context->profileKey,
            'timezone_source' => $timezoneApplied ? 'import' : null,
            'evidence' => $evidence,
            'rejected' => $rejected,
        ];

        $context->occurrence->forceFill(['meta' => $meta])->save();
    }
}