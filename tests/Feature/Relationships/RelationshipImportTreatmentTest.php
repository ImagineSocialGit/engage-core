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
                    'referral_partner' => [
                        'label' => 'Referral Partner',
                        'active' => true,
                        'sort_order' => 20,
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

    public function test_mapped_relationship_and_stage_defaults_apply_only_to_blank_csv_cells(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $existing = Contact::factory()->create(['email' => 'existing@example.test']);
        ContactRelationship::query()->create([
            'contact_id' => $existing->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'referral_partner',
            'is_active' => true,
        ]);
        $csvPath = $this->preview($user, implode("\n", [
            'Email,Relationship Key,Relationship Stage',
            'blank@example.test,realtor,',
            'explicit@example.test,realtor,referral_partner',
            'missing-type@example.test,,',
            'existing@example.test,realtor,',
        ]));

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
                'relationship_key' => 'Relationship Key',
                'relationship_stage' => 'Relationship Stage',
            ],
            'treatments' => [
                'relationship_type' => [
                    'mode' => 'none',
                    'blank_default' => 'realtor',
                ],
                'relationship_stage' => [
                    'mode' => 'none',
                    'blank_default' => 'realtor::target_agent',
                ],
            ],
        ])->assertRedirect(route('crm.contacts.index'));

        foreach ([
            'blank@example.test' => 'target_agent',
            'explicit@example.test' => 'referral_partner',
            'missing-type@example.test' => 'target_agent',
            'existing@example.test' => 'target_agent',
        ] as $email => $stage) {
            $contact = Contact::query()->where('email', $email)->sole();
            $this->assertDatabaseHas('contact_relationships', [
                'contact_id' => $contact->id,
                'relationship_key' => 'realtor',
                'stage_key' => $stage,
            ]);
        }

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame('realtor', data_get($batch->meta, 'mapped_blank_defaults.relationship_key'));
        $this->assertSame('target_agent', data_get($batch->meta, 'mapped_blank_defaults.relationship_stage'));
    }

    public function test_empty_relationship_stage_column_is_still_suggested_for_mapping(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent(
                'contacts.csv',
                "Email,Relationship Key,Relationship Stage\nagent@example.test,realtor,\n",
            ),
        ]);

        $response->assertOk();
        $this->assertSame('Relationship Key', $response->viewData('suggestedMapping')['relationship_key'] ?? null);
        $this->assertSame('Relationship Stage', $response->viewData('suggestedMapping')['relationship_stage'] ?? null);
        $this->assertSame(1, $response->viewData('columnProfiles')['Relationship Stage']['blank_count'] ?? null);
    }

    public function test_blank_stage_without_a_default_preserves_an_existing_stage(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['email' => 'existing@example.test']);
        ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'referral_partner',
            'is_active' => true,
        ]);

        $csvPath = $this->preview($user, implode("\n", [
            'Email,Relationship Key,Relationship Stage',
            'existing@example.test,realtor,',
        ]));

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
                'relationship_key' => 'Relationship Key',
                'relationship_stage' => 'Relationship Stage',
            ],
            'treatments' => [
                'relationship_stage' => ['mode' => 'none', 'blank_default' => ''],
            ],
        ])->assertRedirect(route('crm.contacts.index'));

        $this->assertSame('referral_partner', ContactRelationship::query()
            ->where('contact_id', $contact->id)->sole()->stage_key);
    }

    public function test_a_default_for_blank_stages_requires_a_mapped_stage_column(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $csvPath = $this->preview($user, "Email,Relationship Key\nagent@example.test,realtor\n");

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => ['email' => 'Email', 'relationship_key' => 'Relationship Key'],
            'treatments' => [
                'relationship_stage' => [
                    'mode' => 'none',
                    'blank_default' => 'realtor::target_agent',
                ],
            ],
        ])->assertSessionHasErrors('treatments.relationship_stage.blank_default');

        $this->assertDatabaseCount('contact_import_batches', 0);
    }

    public function test_an_unknown_blank_stage_default_is_rejected_before_import_creation(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $csvPath = $this->preview($user, "Email,Relationship Key,Relationship Stage\nagent@example.test,realtor,\n");

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
                'relationship_key' => 'Relationship Key',
                'relationship_stage' => 'Relationship Stage',
            ],
            'treatments' => [
                'relationship_stage' => [
                    'mode' => 'none',
                    'blank_default' => 'realtor::missing_stage',
                ],
            ],
        ])->assertSessionHasErrors('treatments.relationship_stage');

        $this->assertDatabaseCount('contact_import_batches', 0);
    }

    public function test_conflicting_relationship_defaults_are_rejected_before_import_creation(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $csvPath = $this->preview($user, "Email,Relationship Key,Relationship Stage\nagent@example.test,,\n");

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
                'relationship_key' => 'Relationship Key',
                'relationship_stage' => 'Relationship Stage',
            ],
            'treatments' => [
                'relationship_type' => [
                    'mode' => 'none',
                    'blank_default' => 'consumer',
                ],
                'relationship_stage' => [
                    'mode' => 'none',
                    'blank_default' => 'realtor::target_agent',
                ],
            ],
        ])->assertSessionHasErrors('treatments.relationship_stage.blank_default');

        $this->assertDatabaseCount('contact_import_batches', 0);
    }

    private function preview(User $user, string $contents): string
    {
        $response = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('contacts.csv', $contents),
        ]);

        $response->assertOk();
        preg_match('/name="csv_path"\s+value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        return html_entity_decode($matches[1]);
    }
}