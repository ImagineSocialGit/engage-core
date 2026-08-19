<?php

namespace App\Modules\Mortgage\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\HasRealtorState;
use App\Modules\Mortgage\Enums\MortgageLoanParticipantRole;
use App\Modules\Mortgage\Enums\MortgageLoanRealtorRole;
use App\Modules\Mortgage\Models\ContactMortgageProfile;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Modules\Mortgage\Models\MortgageLoanParticipant;
use App\Modules\Mortgage\Models\MortgageLoanRealtor;
use App\Modules\Mortgage\Models\MortgageRealtorProductionSnapshot;
use App\Modules\Mortgage\Models\MortgageRealtorProfile;
use App\Modules\Mortgage\Models\MortgageStage;
use App\Modules\Relationships\Import\ContactRelationshipImportHandler;
use App\Modules\Relationships\Models\ContactRelationship;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class MortgageContactImportHandler implements ContactImportHandler
{
    private const LOAN_FIELDS = [
        'mortgage_loan_source_record_id',
        'mortgage_loan_stage_key',
        'mortgage_loan_originator',
        'mortgage_loan_purpose',
        'mortgage_loan_program',
        'mortgage_type',
        'mortgage_lien_position',
        'mortgage_loan_amount',
        'mortgage_note_rate',
        'mortgage_sales_price',
        'mortgage_appraised_value',
        'mortgage_cash_to_close',
        'mortgage_subject_property_street',
        'mortgage_subject_property_city',
        'mortgage_subject_property_state',
        'mortgage_subject_property_zip',
        'mortgage_closed_on',
    ];

    private const COBORROWER_FIELDS = [
        'mortgage_coborrower_first_name',
        'mortgage_coborrower_last_name',
        'mortgage_coborrower_email',
        'mortgage_coborrower_phone',
        'mortgage_coborrower_date_of_birth',
        'mortgage_coborrower_mailing_address',
    ];

    private const BUYER_AGENT_FIELDS = [
        'mortgage_buyer_agent_name',
        'mortgage_buyer_agent_email',
        'mortgage_buyer_agent_phone',
    ];

    private const LISTING_AGENT_FIELDS = [
        'mortgage_listing_agent_name',
        'mortgage_listing_agent_email',
        'mortgage_listing_agent_phone',
    ];

    private const REALTOR_PROFILE_FIELDS = [
        'mortgage_realtor_brokerage',
        'mortgage_realtor_license_number',
        'mortgage_realtor_last_referral_at',
        'mortgage_realtor_production_loan_count',
        'mortgage_realtor_production_conventional_count',
        'mortgage_realtor_production_va_count',
        'mortgage_realtor_production_loan_volume',
    ];

    /**
     * Observation metadata such as period/source may be profile defaults, so
     * only actual production measurements may trigger a snapshot.
     */
    private const REALTOR_PRODUCTION_FIELDS = [
        'mortgage_realtor_production_loan_count',
        'mortgage_realtor_production_conventional_count',
        'mortgage_realtor_production_va_count',
        'mortgage_realtor_production_loan_volume',
    ];

    public function __construct(
        private readonly ContactRelationshipImportHandler $relationshipImports,
    ) {}

    public function handle(ContactImportContext $context): void
    {
        $this->importCurrentProfile($context);
        $this->importLoan($context);
        $this->importRealtorProfile($context);
    }

    private function importCurrentProfile(ContactImportContext $context): void
    {
        $hasRealtorValue = $context->value('mortgage_has_realtor');
        $originalLeadValue = $context->value('mortgage_original_lead_at');

        if ($hasRealtorValue === null && $originalLeadValue === null) {
            return;
        }

        $profile = ContactMortgageProfile::query()->firstOrNew([
            'contact_id' => $context->contact->id,
        ]);

        if ($hasRealtorValue !== null) {
            $profile->has_realtor = $this->hasRealtorState($hasRealtorValue);
        }

        if ($originalLeadValue !== null) {
            $candidate = $this->dateTime($originalLeadValue, 'mortgage_original_lead_at');
            $current = $profile->original_lead_at;

            if ($current === null || $candidate->lessThan($current)) {
                $profile->original_lead_at = $candidate;
            }
        }

        $profile->save();
    }

    private function importLoan(ContactImportContext $context): void
    {
        if (! $context->hasAnyValue(self::LOAN_FIELDS)) {
            return;
        }

        $sourceSystem = $context->value('mortgage_loan_source_system')
            ?? $context->batch->source
            ?? 'crm_csv';
        $sourceRecordId = $context->value('mortgage_loan_source_record_id');
        $mortgageStageId = $this->mortgageStageId(
            $context->value('mortgage_loan_stage_key'),
        );

        $attributes = $this->nonNull([
            'mortgage_stage_id' => $mortgageStageId,
            'source_system' => $sourceSystem,
            'source_record_id' => $sourceRecordId,
            'loan_originator' => $context->value('mortgage_loan_originator'),
            'loan_purpose' => $context->value('mortgage_loan_purpose'),
            'loan_program' => $context->value('mortgage_loan_program'),
            'mortgage_type' => $context->value('mortgage_type'),
            'lien_position' => $context->value('mortgage_lien_position'),
            'loan_amount' => $this->decimal($context->value('mortgage_loan_amount'), 'mortgage_loan_amount'),
            'note_rate' => $this->decimal($context->value('mortgage_note_rate'), 'mortgage_note_rate'),
            'sales_price' => $this->decimal($context->value('mortgage_sales_price'), 'mortgage_sales_price'),
            'appraised_value' => $this->decimal($context->value('mortgage_appraised_value'), 'mortgage_appraised_value'),
            'cash_to_close' => $this->decimal($context->value('mortgage_cash_to_close'), 'mortgage_cash_to_close'),
            'subject_property_street' => $context->value('mortgage_subject_property_street'),
            'subject_property_city' => $context->value('mortgage_subject_property_city'),
            'subject_property_state' => $context->value('mortgage_subject_property_state'),
            'subject_property_zip' => $context->value('mortgage_subject_property_zip'),
            'closed_on' => $this->date($context->value('mortgage_closed_on'), 'mortgage_closed_on'),
        ]);

        $fingerprint = $this->fingerprint([
            'contact_email' => strtolower((string) $context->contact->email),
            ...$attributes,
            'coborrower_first_name' => $context->value('mortgage_coborrower_first_name'),
            'coborrower_last_name' => $context->value('mortgage_coborrower_last_name'),
            'coborrower_email' => $this->email($context->value('mortgage_coborrower_email')),
        ]);

        $loanQuery = MortgageLoan::query()
            ->where('source_system', $sourceSystem);

        $loan = $sourceRecordId !== null
            ? $loanQuery->where('source_record_id', $sourceRecordId)->first()
            : $loanQuery->where('source_fingerprint', $fingerprint)->first();

        if ($loan === null) {
            $loan = new MortgageLoan();
        }

        $loan->fill($attributes);
        $loan->source_system = $sourceSystem;
        $loan->source_record_id = $sourceRecordId;
        $loan->source_fingerprint = $fingerprint;
        $loan->save();

        $this->upsertPrimaryBorrower($context, $loan);
        $this->upsertCoborrower($context, $loan);
        $this->upsertLoanRealtor(
            context: $context,
            loan: $loan,
            role: MortgageLoanRealtorRole::BuyerAgent,
            fields: self::BUYER_AGENT_FIELDS,
            nameField: 'mortgage_buyer_agent_name',
            emailField: 'mortgage_buyer_agent_email',
            phoneField: 'mortgage_buyer_agent_phone',
        );
        $this->upsertLoanRealtor(
            context: $context,
            loan: $loan,
            role: MortgageLoanRealtorRole::ListingAgent,
            fields: self::LISTING_AGENT_FIELDS,
            nameField: 'mortgage_listing_agent_name',
            emailField: 'mortgage_listing_agent_email',
            phoneField: 'mortgage_listing_agent_phone',
        );
    }

    private function upsertPrimaryBorrower(ContactImportContext $context, MortgageLoan $loan): void
    {
        MortgageLoanParticipant::query()->updateOrCreate(
            [
                'mortgage_loan_id' => $loan->id,
                'role' => MortgageLoanParticipantRole::PrimaryBorrower->value,
                'position' => 1,
            ],
            $this->nonNull([
                'contact_id' => $context->contact->id,
                'first_name' => $context->contact->first_name,
                'last_name' => $context->contact->last_name,
                'email' => $this->email($context->contact->email),
                'phone' => $context->contact->phone,
                'date_of_birth' => $this->date(
                    $context->value('mortgage_primary_date_of_birth'),
                    'mortgage_primary_date_of_birth',
                ),
                'mailing_address' => $context->value('mortgage_primary_mailing_address'),
            ]),
        );
    }

    private function upsertCoborrower(ContactImportContext $context, MortgageLoan $loan): void
    {
        if (! $context->hasAnyValue(self::COBORROWER_FIELDS)) {
            return;
        }

        $email = $this->email($context->value('mortgage_coborrower_email'));
        $contactId = null;

        if ($email !== null && strcasecmp($email, (string) $context->contact->email) !== 0) {
            $contactId = Contact::query()
                ->where('email', $email)
                ->value('id');
        }

        MortgageLoanParticipant::query()->updateOrCreate(
            [
                'mortgage_loan_id' => $loan->id,
                'role' => MortgageLoanParticipantRole::CoBorrower->value,
                'position' => 2,
            ],
            $this->nonNull([
                'contact_id' => $contactId,
                'first_name' => $context->value('mortgage_coborrower_first_name'),
                'last_name' => $context->value('mortgage_coborrower_last_name'),
                'email' => $email,
                'phone' => $context->value('mortgage_coborrower_phone'),
                'date_of_birth' => $this->date(
                    $context->value('mortgage_coborrower_date_of_birth'),
                    'mortgage_coborrower_date_of_birth',
                ),
                'mailing_address' => $context->value('mortgage_coborrower_mailing_address'),
            ]),
        );
    }

    /**
     * @param array<int, string> $fields
     */
    private function upsertLoanRealtor(
        ContactImportContext $context,
        MortgageLoan $loan,
        MortgageLoanRealtorRole $role,
        array $fields,
        string $nameField,
        string $emailField,
        string $phoneField,
    ): void {
        if (! $context->hasAnyValue($fields)) {
            return;
        }

        $email = $this->email($context->value($emailField));
        $contactId = $email !== null
            ? Contact::query()->where('email', $email)->value('id')
            : null;

        MortgageLoanRealtor::query()->updateOrCreate(
            [
                'mortgage_loan_id' => $loan->id,
                'role' => $role->value,
                'position' => 1,
            ],
            $this->nonNull([
                'contact_id' => $contactId,
                'name' => $context->value($nameField),
                'email' => $email,
                'phone' => $context->value($phoneField),
            ]),
        );
    }

    private function importRealtorProfile(ContactImportContext $context): void
    {
        if (! $context->hasAnyValue(self::REALTOR_PROFILE_FIELDS)) {
            return;
        }

        $relationshipKey = $context->value('relationship_key');

        if ($relationshipKey === null) {
            throw new InvalidArgumentException(
                'Mortgage Realtor profile import requires a mapped Relationship Type Key.',
            );
        }

        $this->relationshipImports->handle($context);

        $relationship = ContactRelationship::query()
            ->where('contact_id', $context->contact->id)
            ->where('relationship_key', $relationshipKey)
            ->firstOrFail();

        $profile = MortgageRealtorProfile::query()->firstOrNew([
            'contact_relationship_id' => $relationship->id,
        ]);

        $profile->fill($this->nonNull([
            'brokerage_name' => $context->value('mortgage_realtor_brokerage'),
            'license_number' => $context->value('mortgage_realtor_license_number'),
            'last_referral_at' => $this->optionalDateTime(
                $context->value('mortgage_realtor_last_referral_at'),
                'mortgage_realtor_last_referral_at',
            ),
        ]));
        $profile->save();

        if ($context->hasAnyValue(self::REALTOR_PRODUCTION_FIELDS)) {
            $this->upsertRealtorProduction($context, $profile);
        }
    }

    private function upsertRealtorProduction(
        ContactImportContext $context,
        MortgageRealtorProfile $profile,
    ): void {
        $periodEndingOn = $this->date(
            $context->value('mortgage_realtor_production_period_ending_on'),
            'mortgage_realtor_production_period_ending_on',
        ) ?? $context->batch->imported_at?->toDateString() ?? now()->toDateString();

        $periodMonths = $this->integer(
            $context->value('mortgage_realtor_production_period_months'),
            'mortgage_realtor_production_period_months',
        ) ?? 12;

        if ($periodMonths < 1 || $periodMonths > 120) {
            throw new InvalidArgumentException(
                'Imported field [mortgage_realtor_production_period_months] must be between 1 and 120.',
            );
        }

        $source = $context->value('mortgage_realtor_production_source')
            ?? $context->batch->source
            ?? 'crm_csv';

        $attributes = [
            'period_ending_on' => $periodEndingOn,
            'period_months' => $periodMonths,
            'loan_count' => $this->integer(
                $context->value('mortgage_realtor_production_loan_count'),
                'mortgage_realtor_production_loan_count',
            ),
            'conventional_count' => $this->integer(
                $context->value('mortgage_realtor_production_conventional_count'),
                'mortgage_realtor_production_conventional_count',
            ),
            'va_count' => $this->integer(
                $context->value('mortgage_realtor_production_va_count'),
                'mortgage_realtor_production_va_count',
            ),
            'loan_volume' => $this->decimal(
                $context->value('mortgage_realtor_production_loan_volume'),
                'mortgage_realtor_production_loan_volume',
            ),
            'source' => $source,
        ];

        $fingerprint = $this->fingerprint($attributes);

        MortgageRealtorProductionSnapshot::query()->updateOrCreate(
            [
                'mortgage_realtor_profile_id' => $profile->id,
                'source_fingerprint' => $fingerprint,
            ],
            [
                ...$attributes,
                'source_fingerprint' => $fingerprint,
            ],
        );
    }

    private function mortgageStageId(?string $key): ?int
    {
        if ($key === null) {
            return null;
        }

        $stageId = MortgageStage::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->value('id');

        if ($stageId === null) {
            throw new InvalidArgumentException(
                "Imported Mortgage stage [{$key}] is not an active configured Mortgage stage.",
            );
        }

        return (int) $stageId;
    }

    private function hasRealtorState(string $value): HasRealtorState
    {
        return match (strtolower(trim($value))) {
            'yes', 'y', 'true', '1' => HasRealtorState::Yes,
            'no', 'n', 'false', '0' => HasRealtorState::No,
            'unknown', 'unsure', 'n/a', 'na' => HasRealtorState::Unknown,
            default => throw new InvalidArgumentException(
                'Imported field [mortgage_has_realtor] must be Yes, No, or Unknown.',
            ),
        };
    }

    private function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }

    private function date(?string $value, string $field): ?string
    {
        return $this->optionalDateTime($value, $field)?->toDateString();
    }

    private function dateTime(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                "Imported field [{$field}] must contain a valid date/time.",
                previous: $exception,
            );
        }
    }

    private function optionalDateTime(?string $value, string $field): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $this->dateTime($value, $field);
    }

    private function decimal(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        $negative = str_starts_with($normalized, '(') && str_ends_with($normalized, ')');
        $normalized = trim($normalized, "() \t\n\r\0\x0B");
        $normalized = str_replace([',', '$', '%'], '', $normalized);

        if ($negative) {
            $normalized = '-'.$normalized;
        }

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException(
                "Imported field [{$field}] must contain a numeric value.",
            );
        }

        return $normalized;
    }

    private function integer(?string $value, string $field): ?int
    {
        $decimal = $this->decimal($value, $field);

        if ($decimal === null) {
            return null;
        }

        if ((float) $decimal !== (float) (int) $decimal) {
            throw new InvalidArgumentException(
                "Imported field [{$field}] must contain a whole number.",
            );
        }

        $integer = (int) $decimal;

        if ($integer < 0) {
            throw new InvalidArgumentException(
                "Imported field [{$field}] cannot be negative.",
            );
        }

        return $integer;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function nonNull(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function fingerprint(array $values): string
    {
        ksort($values);

        return hash(
            'sha256',
            json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}