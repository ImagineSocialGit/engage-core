<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Actions\Contacts\UpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactNameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactNameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_uniform_name_casing_without_destroying_intentional_mixed_case(): void
    {
        $normalizer = app(ContactNameNormalizer::class);

        $this->assertSame('Jeff', $normalizer->normalize('JEFF'));
        $this->assertSame('Jeff', $normalizer->normalize('jeff'));
        $this->assertSame("O'Neill-Smith", $normalizer->normalize("O'NEILL-SMITH"));
        $this->assertSame('Mary Jane', $normalizer->normalize('MARY   JANE'));
        $this->assertSame('McDonald', $normalizer->normalize('McDonald'));
    }

    public function test_create_or_update_contact_normalizes_names_used_by_import_and_manual_create_paths(): void
    {
        $contact = app(CreateOrUpdateContactAction::class)->handle([
            'email' => 'caps@example.test',
            'first_name' => 'JEFF',
            'last_name' => "O'NEILL",
        ]);

        $this->assertSame('Jeff', $contact->first_name);
        $this->assertSame("O'Neill", $contact->last_name);
        $this->assertSame("Jeff O'Neill", $contact->name);
    }

    public function test_contact_update_normalizes_edited_name_fields(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Original',
            'last_name' => 'Person',
            'name' => 'Original Person',
            'email' => 'update-name@example.test',
        ]);

        $contact = app(UpdateContactAction::class)->handle($contact, [
            'first_name' => 'MARY JANE',
            'last_name' => 'SMITH-JONES',
            'name' => 'MARY JANE SMITH-JONES',
        ]);

        $this->assertSame('Mary Jane', $contact->first_name);
        $this->assertSame('Smith-Jones', $contact->last_name);
        $this->assertSame('Mary Jane Smith-Jones', $contact->name);
    }
}