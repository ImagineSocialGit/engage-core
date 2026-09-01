<?php

namespace Tests\Feature\Messaging;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\TokenContracts\ContactTokenSourceProvider;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualMessageFieldCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_copy_contexts_hide_internal_fields_and_present_friendly_examples(): void
    {
        $registry = app(TokenContractRegistry::class);
        $broadcastTokens = $registry->authorableTokens(Broadcast::DEFAULT_DISPATCH_KEY);

        $this->assertContains('first_name', $broadcastTokens);
        $this->assertNotContains('contact.source', $broadcastTokens);
        $this->assertNotContains('contact.subsource', $broadcastTokens);
        $this->assertNotContains(
            'contact.home_purchase_date',
            collect(app(ContactTokenSourceProvider::class)->sources())
                ->pluck('token')
                ->all(),
        );

        $registrationTokens = $registry->authorableTokens('registration_created');

        $this->assertContains('webinar_title', $registrationTokens);
        $this->assertContains('webinar_join_url', $registrationTokens);
        $this->assertNotContains('webinar.id', $registrationTokens);
        $this->assertNotContains('webinar_registration.id', $registrationTokens);

        $fields = collect(app(MessageTemplateAuthoringFieldPresenter::class)
            ->groupsForContext(Broadcast::DEFAULT_DISPATCH_KEY))
            ->flatMap(fn (array $group): array => $group['fields'])
            ->values();
        $firstName = $fields->firstWhere('insert_token', 'first_name');

        $this->assertIsArray($firstName);
        $this->assertSame('First name', $firstName['label']);
        $this->assertSame('Jamie', $firstName['example']);
    }
}