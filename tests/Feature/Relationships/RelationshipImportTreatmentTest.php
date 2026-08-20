<?php

namespace Tests\Feature\Relationships;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Providers\RelationshipsModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelationshipImportTreatmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RelationshipsModuleServiceProvider($this->app))->boot(
            app(ContactImportRegistry::class),
            app(ContactImportTreatmentRegistry::class),
        );

        config()->set('relationships.types', [
            'consumer' => [
                'singular' => 'Lead',
                'plural' => 'Leads',
                'stages' => [],
            ],
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'stages' => [
                    'target_agent' => [
                        'label' => 'Target Agent',
                        'active' => true,
                        'sort_order' => 10,
                    ],
                ],
            ],
        ]);
    }

    public function test_relationship_type_and_stage_treatments_feed_the_existing_relationship_import_handler(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent(
                'contacts.csv',
                implode("\n", [
                    'Email,Contact Type,Agent Stage',
                    'borrower@example.test,Borrower,',
                    'agent@example.test,Agent,Target',
                ]),
            ),
        ]);
        $preview->assertOk();

        preg_match('/name="csv_path"\s+value="([^"]+)"/', $preview->getContent(), $matches);
        $csvPath = html_entity_decode($matches[1]);

        $response = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
            ],
            'treatments' => [
                'relationship_type' => [
                    'mode' => 'column',
                    'source_column' => 'Contact Type',
                    'value_map' => [
                        'borrower' => [
                            'source' => 'Borrower',
                            'values' => ['consumer'],
                        ],
                        'agent' => [
                            'source' => 'Agent',
                            'values' => ['realtor'],
                        ],
                    ],
                ],
                'relationship_stage' => [
                    'mode' => 'column',
                    'source_column' => 'Agent Stage',
                    'value_map' => [
                        'target' => [
                            'source' => 'Target',
                            'values' => ['realtor::target_agent'],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $borrower = Contact::query()->where('email', 'borrower@example.test')->sole();
        $agent = Contact::query()->where('email', 'agent@example.test')->sole();

        $this->assertDatabaseHas('contact_relationships', [
            'contact_id' => $borrower->id,
            'relationship_key' => 'consumer',
            'stage_key' => null,
        ]);
        $this->assertDatabaseHas('contact_relationships', [
            'contact_id' => $agent->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'target_agent',
        ]);
        $this->assertSame(2, ContactRelationship::query()->count());

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(2, data_get($batch->meta, 'treatments.relationship_type.applied_count'));
        $this->assertSame(1, data_get($batch->meta, 'treatments.relationship_stage.applied_count'));
        $this->assertSame(1, data_get($batch->meta, 'treatments.relationship_stage.missing_count'));
    }
}