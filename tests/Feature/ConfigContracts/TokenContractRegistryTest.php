<?php

namespace Tests\Feature\ConfigContracts;

use App\Modules\Core\Models\Contact;
use App\Support\TokenContracts\Data\TokenSourceDefinition;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TokenContractRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_model_tokens_resolve_to_real_non_meta_columns(): void
    {
        $registry = app(TokenContractRegistry::class);

        $this->assertSame([], $registry->validateModelColumns());
        $this->assertArrayHasKey('contact.first_name', $registry->sources());
        $this->assertArrayNotHasKey('contact.meta', $registry->sources());

        foreach ($registry->sources() as $source) {
            $this->assertNotSame('meta', $source->column);
            $this->assertFalse(str_starts_with((string) $source->column, 'meta.'));
        }
    }

    public function test_messaging_contexts_expose_only_registered_contact_columns_and_aliases(): void
    {
        $registry = app(TokenContractRegistry::class);

        $this->assertSame([
            'contact.first_name',
            'first_name',
            'contact.last_name',
            'last_name',
            'contact.name',
            'name',
            'contact.email',
            'email',
            'contact.phone',
            'phone',
        ], $registry->authorableTokens('imported_contact_permission_invitation'));

        $this->assertSame('messaging', $registry->context('consent_granted')->owner);
        $this->assertNotContains('meta', $registry->authorableTokens('consent_granted'));
    }

    public function test_model_token_definitions_cannot_expose_meta_columns_or_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('real non-meta column');

        TokenSourceDefinition::modelColumn(
            token: 'contact.meta.tracking_code',
            owner: 'core',
            label: 'Tracking code',
            description: 'Should not be authorable.',
            modelClass: Contact::class,
            column: 'meta',
        );
    }

    public function test_producer_contexts_exclude_stale_or_secret_webinar_fields(): void
    {
        $registry = app(TokenContractRegistry::class);

        $this->assertArrayNotHasKey('webinar.status', $registry->sources());
        $this->assertArrayNotHasKey('webinar.playback_token', $registry->sources());
        $this->assertArrayNotHasKey('webinar_registration.join_token', $registry->sources());
        $this->assertArrayHasKey('webinar_waitlist_signup.source_page', $registry->sources());
        $this->assertArrayNotHasKey('webinar_waitlist_signup.source', $registry->sources());
        $this->assertContains('webinar_join_url', $registry->authorableTokens('registration_created'));
        $this->assertContains('webinar_playback_url', $registry->authorableTokens('webinar_ended'));
    }
}
