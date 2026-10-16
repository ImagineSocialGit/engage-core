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

}