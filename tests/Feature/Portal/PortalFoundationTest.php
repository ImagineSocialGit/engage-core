<?php

namespace Tests\Feature\Portal;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalAccessGrant;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PortalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('portal'));
        $this->assertFalse($modules->enabled('portal'));
        $this->assertSame(['core'], $modules->dependencies('portal'));
        $this->assertContains(PortalModuleServiceProvider::class, $modules->providers('portal'));
    }

    public function test_portal_foundation_tables_have_durable_generic_columns(): void
    {
        $this->assertTableHasColumns('portal_users', [
            'uuid',
            'name',
            'email',
            'phone',
            'password',
            'remember_token',
            'status',
            'email_verified_at',
            'phone_verified_at',
            'last_login_at',
            'invited_at',
            'accepted_at',
            'disabled_at',
            'source',
            'provider',
            'external_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('portal_contact_links', [
            'portal_user_id',
            'contact_id',
            'relationship',
            'status',
            'is_primary',
            'linked_at',
            'verified_at',
            'revoked_at',
            'source',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('portal_invitations', [
            'portal_user_id',
            'contact_id',
            'email',
            'phone',
            'token_hash',
            'status',
            'channel',
            'purpose',
            'expires_at',
            'sent_at',
            'accepted_at',
            'revoked_at',
            'accepted_ip',
            'accepted_user_agent',
            'source',
            'provider',
            'external_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('portal_access_grants', [
            'portal_user_id',
            'contact_id',
            'grantable_type',
            'grantable_id',
            'capability',
            'status',
            'starts_at',
            'expires_at',
            'granted_by_type',
            'granted_by_id',
            'revoked_at',
            'source',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_portal_users_link_to_contacts_without_owning_contact_identity(): void
    {
        $portalUser = PortalUser::factory()->active()->create();
        $contact = Contact::factory()->create();

        $link = PortalContactLink::factory()->create([
            'portal_user_id' => $portalUser->id,
            'contact_id' => $contact->id,
            'relationship' => PortalContactLink::RELATIONSHIP_SELF,
        ]);

        $this->assertTrue($portalUser->contactLinks->contains($link));
        $this->assertTrue($link->portalUser->is($portalUser));
        $this->assertTrue($link->contact->is($contact));
        $this->assertSame(PortalContactLink::STATUS_ACTIVE, $link->status);
    }

    public function test_portal_invitations_track_account_access_lifecycle_without_message_delivery_ownership(): void
    {
        $portalUser = PortalUser::factory()->create();
        $contact = Contact::factory()->create();

        $invitation = PortalInvitation::factory()->sent()->create([
            'portal_user_id' => $portalUser->id,
            'contact_id' => $contact->id,
            'purpose' => PortalInvitation::PURPOSE_ACCOUNT_ACCESS,
            'channel' => 'email',
        ]);

        $this->assertTrue($portalUser->invitations->contains($invitation));
        $this->assertTrue($invitation->portalUser->is($portalUser));
        $this->assertTrue($invitation->contact->is($contact));
        $this->assertSame(PortalInvitation::STATUS_SENT, $invitation->status);
        $this->assertSame(PortalInvitation::PURPOSE_ACCOUNT_ACCESS, $invitation->purpose);
    }

    public function test_portal_access_grants_can_point_to_generic_grantable_records(): void
    {
        $portalUser = PortalUser::factory()->active()->create();
        $contact = Contact::factory()->create();

        $grant = PortalAccessGrant::factory()
            ->forGrantable($contact, PortalAccessGrant::CAPABILITY_VIEW)
            ->create([
                'portal_user_id' => $portalUser->id,
                'contact_id' => $contact->id,
            ]);

        $this->assertTrue($portalUser->accessGrants->contains($grant));
        $this->assertTrue($grant->portalUser->is($portalUser));
        $this->assertTrue($grant->contact->is($contact));
        $this->assertTrue($grant->grantable->is($contact));
        $this->assertSame(PortalAccessGrant::CAPABILITY_VIEW, $grant->capability);
        $this->assertSame(PortalAccessGrant::STATUS_ACTIVE, $grant->status);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Missing column [{$table}.{$column}].",
            );
        }
    }
}
