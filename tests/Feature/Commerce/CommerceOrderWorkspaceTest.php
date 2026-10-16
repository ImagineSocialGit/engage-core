<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationService;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['commerce']);
    }

    public function test_order_index_and_detail_respect_contact_visibility(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');

        $visibleContact = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $hiddenContact = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
        ]);

        $visibleOrder = $this->orderForContact($visibleContact, 'visible-order');
        $hiddenOrder = $this->orderForContact($hiddenContact, 'hidden-order');
        $unlinkedOrder = CommerceOrder::factory()->create([
            'commerce_customer_id' => null,
            'contact_id' => null,
            'external_id' => 'unlinked-order',
        ]);

        $response = $this->actingAs($member)
            ->get(route('crm.commerce.orders.index'))
            ->assertOk();

        $orders = $response->viewData('orders');
        $summary = $response->viewData('summary');

        $this->assertSame(1, $orders->total());
        $this->assertSame((int) $visibleOrder->getKey(), (int) $orders->first()->getKey());
        $this->assertSame(1, $summary['total']);

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $visibleOrder))
            ->assertOk();

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $hiddenOrder))
            ->assertNotFound();

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $unlinkedOrder))
            ->assertNotFound();
    }

    public function test_customer_linked_legacy_order_inherits_contact_visibility(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');

        $visibleContact = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $hiddenContact = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
        ]);
        $visibleCustomer = CommerceCustomer::factory()->create([
            'contact_id' => $visibleContact->getKey(),
        ]);
        $hiddenCustomer = CommerceCustomer::factory()->create([
            'contact_id' => $hiddenContact->getKey(),
        ]);
        $visibleLegacyOrder = CommerceOrder::factory()->create([
            'commerce_customer_id' => $visibleCustomer->getKey(),
            'contact_id' => null,
            'external_id' => 'visible-legacy-order',
        ]);
        $hiddenLegacyOrder = CommerceOrder::factory()->create([
            'commerce_customer_id' => $hiddenCustomer->getKey(),
            'contact_id' => null,
            'external_id' => 'hidden-legacy-order',
        ]);

        $orders = $this->actingAs($member)
            ->get(route('crm.commerce.orders.index'))
            ->assertOk()
            ->viewData('orders');

        $this->assertSame(1, $orders->total());
        $this->assertSame(
            (int) $visibleLegacyOrder->getKey(),
            (int) $orders->first()->getKey(),
        );

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $visibleLegacyOrder))
            ->assertOk();

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $hiddenLegacyOrder))
            ->assertNotFound();
    }


    private function orderForContact(Contact $contact, string $externalId): CommerceOrder
    {
        $customer = CommerceCustomer::factory()->create([
            'contact_id' => $contact->getKey(),
        ]);

        return CommerceOrder::factory()
            ->forCustomer($customer)
            ->create([
                'external_id' => $externalId,
            ]);
    }

    private function profile(User $user, string $role): UserAccessProfile
    {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => true,
            'capability_overrides' => null,
        ]);
    }
}