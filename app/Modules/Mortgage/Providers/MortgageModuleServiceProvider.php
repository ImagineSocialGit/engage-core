<?php

namespace App\Modules\Mortgage\Providers;

use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Mortgage\Import\MortgageContactImportHandler;
use App\Modules\Mortgage\ModuleFacts\MortgageModuleFactProvider;
use App\Support\ModuleFacts\ModuleFactRegistry;
use Illuminate\Support\ServiceProvider;

class MortgageModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(
            MortgageModuleFactProvider::class,
            ModuleFactRegistry::PROVIDER_TAG,
        );
    }

    public function boot(ContactImportRegistry $contactImports): void
    {
        $contactImports
            ->registerFields($this->importFields())
            ->registerHandler(MortgageContactImportHandler::class);
    }

    /**
     * @return array<int, ContactImportField>
     */
    private function importFields(): array
    {
        return [
            ContactImportField::make(
                key: 'mortgage_has_realtor',
                label: 'Has Realtor?',
                section: 'Mortgage — Current Consumer',
                description: 'Yes, No, or Unknown.',
                sort: 3000,
            ),
            ContactImportField::make(
                key: 'mortgage_original_lead_at',
                label: 'Original Lead Date',
                section: 'Mortgage — Current Consumer',
                description: 'Keeps the earliest known imported lead date.',
                sort: 3010,
            ),

            ContactImportField::make(
                key: 'mortgage_loan_source_system',
                label: 'Loan Source System',
                section: 'Mortgage — Loan History',
                description: 'Stable source system key when the source file provides one. Otherwise the import batch source is used.',
                sort: 3100,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_source_record_id',
                label: 'Loan Source Record ID',
                section: 'Mortgage — Loan History',
                description: 'Stable external loan/file identifier when available.',
                sort: 3110,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_stage_key',
                label: 'Mortgage Loan Stage Key',
                section: 'Mortgage — Loan History',
                sort: 3120,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_originator',
                label: 'Loan Originator',
                section: 'Mortgage — Loan History',
                sort: 3130,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_purpose',
                label: 'Loan Purpose',
                section: 'Mortgage — Loan History',
                sort: 3140,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_program',
                label: 'Loan Program',
                section: 'Mortgage — Loan History',
                sort: 3150,
            ),
            ContactImportField::make(
                key: 'mortgage_type',
                label: 'Mortgage Type',
                section: 'Mortgage — Loan History',
                sort: 3160,
            ),
            ContactImportField::make(
                key: 'mortgage_lien_position',
                label: 'Lien Position',
                section: 'Mortgage — Loan History',
                sort: 3170,
            ),
            ContactImportField::make(
                key: 'mortgage_loan_amount',
                label: 'Loan Amount',
                section: 'Mortgage — Loan History',
                sort: 3180,
            ),
            ContactImportField::make(
                key: 'mortgage_note_rate',
                label: 'Note Rate',
                section: 'Mortgage — Loan History',
                sort: 3190,
            ),
            ContactImportField::make(
                key: 'mortgage_sales_price',
                label: 'Sales Price',
                section: 'Mortgage — Loan History',
                sort: 3200,
            ),
            ContactImportField::make(
                key: 'mortgage_appraised_value',
                label: 'Appraised Value',
                section: 'Mortgage — Loan History',
                sort: 3210,
            ),
            ContactImportField::make(
                key: 'mortgage_cash_to_close',
                label: 'Cash To Close',
                section: 'Mortgage — Loan History',
                sort: 3220,
            ),
            ContactImportField::make(
                key: 'mortgage_subject_property_street',
                label: 'Subject Property Street',
                section: 'Mortgage — Loan History',
                sort: 3230,
            ),
            ContactImportField::make(
                key: 'mortgage_subject_property_city',
                label: 'Subject Property City',
                section: 'Mortgage — Loan History',
                sort: 3240,
            ),
            ContactImportField::make(
                key: 'mortgage_subject_property_state',
                label: 'Subject Property State',
                section: 'Mortgage — Loan History',
                sort: 3250,
            ),
            ContactImportField::make(
                key: 'mortgage_subject_property_zip',
                label: 'Subject Property ZIP',
                section: 'Mortgage — Loan History',
                sort: 3260,
            ),
            ContactImportField::make(
                key: 'mortgage_closed_on',
                label: 'Closed On',
                section: 'Mortgage — Loan History',
                sort: 3270,
            ),
            ContactImportField::make(
                key: 'mortgage_primary_date_of_birth',
                label: 'Primary Borrower Date of Birth',
                section: 'Mortgage — Primary Borrower Snapshot',
                sort: 3300,
            ),
            ContactImportField::make(
                key: 'mortgage_primary_mailing_address',
                label: 'Primary Borrower Mailing Address',
                section: 'Mortgage — Primary Borrower Snapshot',
                sort: 3310,
            ),

            ContactImportField::make(
                key: 'mortgage_coborrower_first_name',
                label: 'Co-Borrower First Name',
                section: 'Mortgage — Co-Borrower Snapshot',
                sort: 3400,
            ),
            ContactImportField::make(
                key: 'mortgage_coborrower_last_name',
                label: 'Co-Borrower Last Name',
                section: 'Mortgage — Co-Borrower Snapshot',
                sort: 3410,
            ),
            ContactImportField::make(
                key: 'mortgage_coborrower_email',
                label: 'Co-Borrower Email',
                section: 'Mortgage — Co-Borrower Snapshot',
                description: 'Exact-email linkage reuses an existing Contact only when the email differs from the primary borrower. Shared-email co-borrowers remain snapshot-only.',
                sort: 3420,
            ),
            ContactImportField::make(
                key: 'mortgage_coborrower_phone',
                label: 'Co-Borrower Phone',
                section: 'Mortgage — Co-Borrower Snapshot',
                sort: 3430,
            ),
            ContactImportField::make(
                key: 'mortgage_coborrower_date_of_birth',
                label: 'Co-Borrower Date of Birth',
                section: 'Mortgage — Co-Borrower Snapshot',
                sort: 3440,
            ),
            ContactImportField::make(
                key: 'mortgage_coborrower_mailing_address',
                label: 'Co-Borrower Mailing Address',
                section: 'Mortgage — Co-Borrower Snapshot',
                sort: 3450,
            ),

            ContactImportField::make(
                key: 'mortgage_buyer_agent_name',
                label: 'Buyer Agent Name',
                section: 'Mortgage — Loan Realtors',
                sort: 3500,
            ),
            ContactImportField::make(
                key: 'mortgage_buyer_agent_email',
                label: 'Buyer Agent Email',
                section: 'Mortgage — Loan Realtors',
                sort: 3510,
            ),
            ContactImportField::make(
                key: 'mortgage_buyer_agent_phone',
                label: 'Buyer Agent Phone',
                section: 'Mortgage — Loan Realtors',
                sort: 3520,
            ),
            ContactImportField::make(
                key: 'mortgage_listing_agent_name',
                label: 'Listing Agent Name',
                section: 'Mortgage — Loan Realtors',
                sort: 3530,
            ),
            ContactImportField::make(
                key: 'mortgage_listing_agent_email',
                label: 'Listing Agent Email',
                section: 'Mortgage — Loan Realtors',
                sort: 3540,
            ),
            ContactImportField::make(
                key: 'mortgage_listing_agent_phone',
                label: 'Listing Agent Phone',
                section: 'Mortgage — Loan Realtors',
                sort: 3550,
            ),

            ContactImportField::make(
                key: 'mortgage_realtor_brokerage',
                label: 'Realtor Brokerage',
                section: 'Mortgage — Realtor Profile',
                sort: 3600,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_license_number',
                label: 'Realtor License Number',
                section: 'Mortgage — Realtor Profile',
                sort: 3610,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_last_referral_at',
                label: 'Last Referral At',
                section: 'Mortgage — Realtor Profile',
                sort: 3620,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_period_ending_on',
                label: 'Production Period Ending On',
                section: 'Mortgage — Realtor Production',
                description: 'Falls back to the import date when a trailing-period export does not provide its own observation date.',
                sort: 3700,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_period_months',
                label: 'Production Period Months',
                section: 'Mortgage — Realtor Production',
                description: 'Defaults to 12 months when production values are present.',
                sort: 3710,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_loan_count',
                label: 'Loan Count',
                section: 'Mortgage — Realtor Production',
                sort: 3720,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_conventional_count',
                label: 'Conventional Count',
                section: 'Mortgage — Realtor Production',
                sort: 3730,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_va_count',
                label: 'VA Count',
                section: 'Mortgage — Realtor Production',
                sort: 3740,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_loan_volume',
                label: 'Loan Volume',
                section: 'Mortgage — Realtor Production',
                sort: 3750,
            ),
            ContactImportField::make(
                key: 'mortgage_realtor_production_source',
                label: 'Production Source',
                section: 'Mortgage — Realtor Production',
                sort: 3760,
            ),
        ];
    }
}