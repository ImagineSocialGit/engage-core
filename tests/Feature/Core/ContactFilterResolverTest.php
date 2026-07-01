<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactFilterResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_all_contacts(): void
    {
        $contacts = Contact::factory()->count(2)->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'all',
        ]);

        $this->assertSame(
            $contacts->pluck('id')->sort()->values()->all(),
            $resolved->pluck('id')->values()->all(),
        );
    }

    public function test_it_resolves_contacts_by_contact_ids(): void
    {
        $included = Contact::factory()->create();
        $excluded = Contact::factory()->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'contact_ids',
            'contact_ids' => [
                $included->id,
                $included->id,
                0,
                null,
                'not-an-id',
            ],
        ]);

        $this->assertSame([$included->id], $resolved->pluck('id')->values()->all());
        $this->assertNotContains($excluded->id, $resolved->pluck('id')->values()->all());
    }

    public function test_it_resolves_imported_contacts_from_core_owned_import_facts(): void
    {
        $sourceImported = Contact::factory()->create([
            'source' => 'import',
            'meta' => [],
        ]);

        $metaImported = Contact::factory()->create([
            'source' => 'manual',
            'meta' => [
                'imported' => true,
            ],
        ]);

        $importedAt = Contact::factory()->create([
            'source' => 'manual',
            'meta' => [
                'imported_at' => now()->toISOString(),
            ],
        ]);

        $notImported = Contact::factory()->create([
            'source' => 'manual',
            'meta' => [
                'imported' => false,
            ],
        ]);

        $noImportFacts = Contact::factory()->create([
            'source' => 'webinar',
            'meta' => [],
        ]);

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'imported',
        ]);

        $this->assertSame(
            [
                $sourceImported->id,
                $metaImported->id,
                $importedAt->id,
            ],
            $resolved->pluck('id')->values()->all(),
        );

        $this->assertNotContains($notImported->id, $resolved->pluck('id')->values()->all());
        $this->assertNotContains($noImportFacts->id, $resolved->pluck('id')->values()->all());
    }

    public function test_it_resolves_contacts_by_tags(): void
    {
        $tagged = Contact::factory()->create();
        $alsoTagged = Contact::factory()->create();
        $untagged = Contact::factory()->create();

        ContactTag::query()->create([
            'contact_id' => $tagged->id,
            'tag' => 'homebuyer',
        ]);

        ContactTag::query()->create([
            'contact_id' => $alsoTagged->id,
            'tag' => 'va_buyer',
        ]);

        ContactTag::query()->create([
            'contact_id' => $untagged->id,
            'tag' => 'refinance',
        ]);

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'tag',
            'tags' => [
                'homebuyer',
                'VA-Buyer',
            ],
        ]);

        $this->assertSame(
            [$tagged->id, $alsoTagged->id],
            $resolved->pluck('id')->values()->all(),
        );

        $this->assertNotContains($untagged->id, $resolved->pluck('id')->values()->all());
    }

    public function test_it_resolves_no_contacts_for_empty_contact_id_filters(): void
    {
        Contact::factory()->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'contact_ids',
            'contact_ids' => [],
        ]);

        $this->assertCount(0, $resolved);
    }

    public function test_it_resolves_no_contacts_for_empty_tag_filters(): void
    {
        Contact::factory()->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'tag',
            'tags' => [],
        ]);

        $this->assertCount(0, $resolved);
    }

    public function test_it_resolves_no_contacts_for_unknown_filter_types(): void
    {
        Contact::factory()->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'not_supported',
        ]);

        $this->assertCount(0, $resolved);
    }
}