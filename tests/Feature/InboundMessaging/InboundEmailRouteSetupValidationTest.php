<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Validation\InboundMessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailRouteSetupValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_routes_require_valid_inbound_domain(): void
    {
        InboundEmailRoute::query()->create([
            'key' => 'arive_application',
            'local_part' => 'arive+application',
            'label' => 'Arive application',
            'source' => 'arive',
            'context_key' => 'application',
            'is_active' => true,
        ]);

        config()->set('messaging.email.inbound_domain', null);

        $codes = collect(app(InboundMessagingSetupValidationContributor::class)->findings())
            ->pluck('code')
            ->all();

        $this->assertContains(
            'inbound_messaging.email_routes.inbound_domain_missing',
            $codes,
        );

        config()->set('messaging.email.inbound_domain', 'replies.example.test');

        $codes = collect(app(InboundMessagingSetupValidationContributor::class)->findings())
            ->pluck('code')
            ->all();

        $this->assertNotContains(
            'inbound_messaging.email_routes.inbound_domain_missing',
            $codes,
        );
    }

    public function test_invalid_route_local_part_is_reported(): void
    {
        config()->set('messaging.email.inbound_domain', 'replies.example.test');

        InboundEmailRoute::query()->create([
            'key' => 'invalid_route',
            'local_part' => 'Not A Mailbox',
            'label' => 'Invalid route',
            'source' => 'fixture',
            'context_key' => null,
            'is_active' => true,
        ]);

        $codes = collect(app(InboundMessagingSetupValidationContributor::class)->findings())
            ->pluck('code')
            ->all();

        $this->assertContains(
            'inbound_messaging.email_routes.local_part_invalid',
            $codes,
        );
    }
}