<?php

namespace Tests\Feature\ProjectState;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectStateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ForceStagingAccess::class);

        config()->set('client.key', 'test-client');
        config()->set('project_state.authorized_email', 'owner@example.com');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_project_state_page_is_restricted_to_the_configured_owner_email(): void
    {
        $owner = $this->user('owner@example.com');
        $other = $this->user('other@example.com');

        $this->get(route('crm.project-state.index'))->assertRedirect();

        $this
            ->actingAs($other)
            ->get(route('crm.project-state.index'))
            ->assertForbidden();

        $this
            ->actingAs($owner)
            ->get(route('crm.project-state.index'))
            ->assertOk()
            ->assertSee('Download current state')
            ->assertSee('Validate or apply current-format state');
    }

    public function test_owner_can_download_a_current_format_core_state_file(): void
    {
        $owner = $this->user('owner@example.com');
        $this->seedCoreState();

        $response = $this
            ->actingAs($owner)
            ->post(route('crm.project-state.export'), [
                'current_password' => 'secret-password',
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');
        $response->assertDownload();

        $document = app(ProjectStateManager::class)->decode(
            $response->streamedContent(),
        );

        $this->assertSame('engage-core-project-state', $document['format']);
        $this->assertSame(6, $document['version']);
        $this->assertSame('test-client', $document['client_key']);
        $this->assertStringStartsWith('sha256:', $document['checksum']);

        $tables = $document['sections']['core']['tables'];

        $this->assertCount(1, $tables['contact_statuses']);
        $this->assertCount(1, $tables['contact_import_batches']);
        $this->assertCount(1, $tables['contacts']);
        $this->assertCount(1, $tables['contact_tags']);
        $this->assertCount(1, $tables['notes']);
        $this->assertCount(1, $tables['site_settings']);
        $this->assertEquals(['source' => 'test'], $tables['contacts'][0]['meta']);
    }

    public function test_validation_reports_nonempty_runtime_tables_without_mutating_state(): void
    {
        $owner = $this->user('owner@example.com');
        $this->seedCoreState();

        $projectState = app(ProjectStateManager::class);
        $contents = $projectState->encode($projectState->export());

        $response = $this
            ->actingAs($owner)
            ->post(route('crm.project-state.import'), [
                'operation' => 'validate',
                'state_file' => UploadedFile::fake()->createWithContent(
                    'project-state.json',
                    $contents,
                ),
            ]);

        $response
            ->assertOk()
            ->assertSee('The file cannot be imported')
            ->assertSee('Target table [contacts] must be empty before import.');

        $this->assertDatabaseHas('contacts', [
            'id' => 60,
            'email' => 'contact@example.com',
        ]);
    }

    public function test_owner_can_apply_a_valid_core_state_file_after_a_clean_rebuild(): void
    {
        $owner = $this->user('owner@example.com');
        $this->seedCoreState();

        $projectState = app(ProjectStateManager::class);
        $contents = $projectState->encode($projectState->export());

        DB::table('notes')->delete();
        DB::table('contact_tags')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_import_batches')->delete();
        DB::table('site_settings')->delete();
        DB::table('contact_statuses')
            ->where('key', 'new')
            ->update([
                'name' => 'Changed by fresh presets',
                'description' => null,
                'updated_at' => now(),
            ]);

        $validateResponse = $this
            ->actingAs($owner)
            ->post(route('crm.project-state.import'), [
                'operation' => 'validate',
                'state_file' => UploadedFile::fake()->createWithContent(
                    'project-state.json',
                    $contents,
                ),
            ]);

        $validateResponse
            ->assertOk()
            ->assertSee('The file matches the current contract')
            ->assertDontSee('Import applied');

        $this->assertDatabaseMissing('contacts', [
            'id' => 60,
        ]);

        $applyResponse = $this
            ->actingAs($owner)
            ->post(route('crm.project-state.import'), [
                'operation' => 'apply',
                'current_password' => 'secret-password',
                'confirmation' => 'IMPORT',
                'state_file' => UploadedFile::fake()->createWithContent(
                    'project-state.json',
                    $contents,
                ),
            ]);

        $applyResponse
            ->assertOk()
            ->assertSee('Import applied')
            ->assertSee('The file matches the current contract');

        $this->assertDatabaseHas('contact_statuses', [
            'key' => 'new',
            'name' => 'New',
            'description' => 'New contact status.',
        ]);
        $this->assertDatabaseHas('contact_import_batches', [
            'id' => 50,
            'name' => 'Production import',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => 60,
            'contact_import_batch_id' => 50,
            'email' => 'contact@example.com',
        ]);
        $this->assertDatabaseHas('contact_tags', [
            'id' => 70,
            'contact_id' => 60,
            'tag' => 'priority',
        ]);
        $this->assertDatabaseHas('notes', [
            'id' => 80,
            'contact_id' => 60,
            'body' => 'Imported production note.',
        ]);
        $this->assertDatabaseHas('site_settings', [
            'key' => 'booking_url',
            'value' => 'https://example.com/book',
        ]);
    }

    public function test_apply_requires_the_owner_password_and_explicit_confirmation(): void
    {
        $owner = $this->user('owner@example.com');
        $this->seedCoreState();

        $projectState = app(ProjectStateManager::class);
        $contents = $projectState->encode($projectState->export());

        DB::table('notes')->delete();
        DB::table('contact_tags')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_import_batches')->delete();

        $this
            ->actingAs($owner)
            ->post(route('crm.project-state.import'), [
                'operation' => 'apply',
                'current_password' => 'wrong-password',
                'confirmation' => 'IMPORT',
                'state_file' => UploadedFile::fake()->createWithContent(
                    'project-state.json',
                    $contents,
                ),
            ])
            ->assertSessionHasErrors('current_password');

        $this
            ->actingAs($owner)
            ->post(route('crm.project-state.import'), [
                'operation' => 'apply',
                'current_password' => 'secret-password',
                'confirmation' => 'import',
                'state_file' => UploadedFile::fake()->createWithContent(
                    'project-state.json',
                    $contents,
                ),
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseMissing('contacts', [
            'id' => 60,
        ]);
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
    }

    private function seedCoreState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contact_statuses')->insert([
            'id' => 40,
            'key' => 'new',
            'name' => 'New',
            'description' => 'New contact status.',
            'category' => 'general',
            'color' => null,
            'is_core' => true,
            'is_active' => true,
            'is_customized' => false,
            'customized_at' => null,
            'sort_order' => 10,
            'source_version' => '1',
            'meta' => json_encode(['source' => 'preset']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_import_batches')->insert([
            'id' => 50,
            'name' => 'Production import',
            'source' => 'legacy_crm',
            'original_filename' => 'contacts.csv',
            'status' => 'completed',
            'imported_at' => $now,
            'contact_count' => 1,
            'successful_count' => 1,
            'failed_count' => 0,
            'meta' => json_encode(['source' => 'test']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'name' => 'Test Contact',
            'email' => 'contact@example.com',
            'phone' => '+15555550123',
            'source' => 'import',
            'subsource' => 'legacy_crm',
            'contact_import_batch_id' => 50,
            'last_contacted_at' => $now,
            'last_activity_at' => $now,
            'meta' => json_encode(['source' => 'test']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_tags')->insert([
            'id' => 70,
            'contact_id' => 60,
            'tag' => 'priority',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('notes')->insert([
            'id' => 80,
            'contact_id' => 60,
            'related_type' => null,
            'related_id' => null,
            'body' => 'Imported production note.',
            'meta' => json_encode(['source' => 'test']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('site_settings')->insert([
            'id' => 90,
            'key' => 'booking_url',
            'value' => 'https://example.com/book',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}