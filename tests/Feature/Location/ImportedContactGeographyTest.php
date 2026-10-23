<?php

namespace Tests\Feature\Location;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Import\ContactImportEvidenceHandler;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Location\Import\ImportContactGeographyHandler;
use App\Modules\Location\Models\ContactLocation;
use App\Modules\Location\Models\Location;
use App\Modules\Location\Providers\LocationModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedContactGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_location_registers_a_projection_handler_via_core_import_contract(): void
    {
        // Explicit provider boot exercises the optional module's public hook
        // without making Core depend on Location or requiring a UI assertion.
        (new LocationModuleServiceProvider(app()))->boot(app(ContactImportRegistry::class));

        $this->assertContains(
            'country_code',
            app(ContactImportRegistry::class)->fieldKeys(),
        );

        $contact = Contact::factory()->create();
        $context = $this->context($contact, 'us', 'tn');
        app(ContactImportRegistry::class)->handleModuleImports($context);

        $link = ContactLocation::query()->where('contact_id', $contact->getKey())
            ->where('source', 'external_geo_estimate')->firstOrFail();
        $this->assertSame(ContactLocation::TYPE_OTHER, $link->type);
        $this->assertFalse($link->is_primary);
        $this->assertNull($link->verified_at);
        $this->assertSame('US', $link->location->country);
        $this->assertSame('TN', $link->location->region);
        $this->assertSame(Location::TYPE_REGION, $link->location->type);
        $this->assertSame('region', $link->location->precision);
        $this->assertNull($link->location->latitude);
        $this->assertNull($link->location->address_line_1);
        $this->assertSame('us', $context->occurrence->fresh()->meta['import_evidence']['evidence']['country_code_raw']);
        $this->assertSame('tn', $context->occurrence->fresh()->meta['import_evidence']['evidence']['region_code_raw']);
    }

    public function test_reimport_updates_only_the_import_owned_estimate_not_a_verified_address(): void
    {
        $contact = Contact::factory()->create();
        $verified = Location::factory()->create(['country' => 'US', 'region' => 'NY']);
        $verifiedLink = ContactLocation::query()->create([
            'contact_id' => $contact->getKey(),
            'location_id' => $verified->getKey(),
            'type' => ContactLocation::TYPE_HOME,
            'source' => 'manual',
            'status' => ContactLocation::STATUS_ACTIVE,
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        app(ImportContactGeographyHandler::class)->handle($this->context($contact, 'US', 'TN'));
        app(ImportContactGeographyHandler::class)->handle($this->context($contact, 'US', 'IL'));

        $this->assertSame(1, ContactLocation::query()->where('contact_id', $contact->getKey())
            ->where('source', 'external_geo_estimate')->count());
        $this->assertSame('IL', ContactLocation::query()->where('contact_id', $contact->getKey())
            ->where('source', 'external_geo_estimate')->firstOrFail()->location->region);
        $this->assertSame($verified->getKey(), $verifiedLink->fresh()->location_id);
        $this->assertTrue($verifiedLink->fresh()->is_primary);
        $this->assertNotNull($verifiedLink->fresh()->verified_at);
    }

    public function test_invalid_country_is_preserved_as_raw_import_evidence_without_a_location(): void
    {
        $contact = Contact::factory()->create();
        $context = $this->context($contact, 'United States', 'TN');

        app(ContactImportEvidenceHandler::class)->handle($context);
        app(ImportContactGeographyHandler::class)->handle($context);

        $this->assertSame(0, ContactLocation::query()->where('contact_id', $contact->getKey())->count());
        $meta = $context->occurrence->fresh()->meta;
        $this->assertSame('United States', $meta['import_evidence']['evidence']['country_code_raw']);
        $this->assertNull($meta['imported_geography']['location_id']);
        $this->assertSame('United States', $meta['imported_geography']['rejected']['country_code']);
    }

    private function context(Contact $contact, string $country, string $region): ContactImportContext
    {
        $batch = ContactImportBatch::query()->create([
            'name' => 'External audience',
            'source' => 'external',
            'original_filename' => 'audience.csv',
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now(),
            'contact_count' => 1,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [],
        ]);
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', $contact->email.$country.$region),
            'meta' => [],
        ]);

        return new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: ['CC' => $country, 'REGION' => $region],
            mapping: ['country_code' => 'CC', 'region_code' => 'REGION'],
            profileKey: 'external_audience',
        );
    }
}