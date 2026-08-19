<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Services\Contacts\ContactImportProfileRegistry;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class ContactImportProfileRegistryTest extends TestCase
{
    public function test_profile_matches_filename_and_suggests_normalized_header_aliases(): void
    {
        Config::set('contact_imports.profiles', [
            'known_export' => [
                'label' => 'Known Export',
                'filename_contains' => ['known export'],
                'defaults' => [
                    'source' => 'Database',
                ],
                'aliases' => [
                    'email' => ['Email Address'],
                    'first_name' => ['First Name'],
                ],
            ],
        ]);

        $registry = app(ContactImportProfileRegistry::class);
        $profile = $registry->findByFilename('Known-Export.xlsx.csv');

        $this->assertNotNull($profile);
        $this->assertSame('known_export', $profile->key);
        $this->assertSame('Database', $profile->defaults['source']);
        $this->assertEquals([
            'email' => 'EMAIL_ADDRESS',
            'first_name' => 'First-Name',
        ], $registry->suggestedMapping($profile, [
            'First-Name',
            'EMAIL_ADDRESS',
        ]));
    }

    public function test_ambiguous_filename_match_does_not_silently_choose_a_profile(): void
    {
        Config::set('contact_imports.profiles', [
            'one' => [
                'label' => 'One',
                'filename_contains' => ['borrowers'],
            ],
            'two' => [
                'label' => 'Two',
                'filename_contains' => ['borrowers export'],
            ],
        ]);

        $this->assertNull(
            app(ContactImportProfileRegistry::class)
                ->findByFilename('Borrowers Export.csv'),
        );
    }

    public function test_profile_rejects_unknown_fields_and_default_contact_identity(): void
    {
        Config::set('contact_imports.profiles', [
            'bad' => [
                'label' => 'Bad',
                'defaults' => [
                    'email' => 'same@example.test',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(ContactImportProfileRegistry::class)->all();
    }
}