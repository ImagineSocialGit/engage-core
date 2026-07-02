<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use Tests\TestCase;

class MessageChannelAvailabilityTest extends TestCase
{
    public function test_it_returns_channels_visible_for_a_surface(): void
    {
        config([
            'messaging.channel_availability.email.runtime_supported' => true,
            'messaging.channel_availability.email.provider_enabled' => true,
            'messaging.channel_availability.email.surfaces.broadcasts' => true,

            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
        ]);

        $channels = app(MessageChannelAvailability::class)->visibleChannelsForSurface(
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
        );

        $this->assertSame(['email', 'sms'], $channels);
    }

    public function test_it_hides_channels_that_are_not_visible_for_a_surface(): void
    {
        config([
            'messaging.channel_availability.email.runtime_supported' => true,
            'messaging.channel_availability.email.surfaces.broadcasts' => true,

            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => false,
        ]);

        $channels = app(MessageChannelAvailability::class)->visibleChannelsForSurface(
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
        );

        $this->assertSame(['email'], $channels);
    }

    public function test_it_can_require_provider_availability(): void
    {
        config([
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => false,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
        ]);

        $this->assertFalse(app(MessageChannelAvailability::class)->isVisibleForSurface(
            channel: 'sms',
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
            requireProvider: true,
        ));

        $this->assertTrue(app(MessageChannelAvailability::class)->isVisibleForSurface(
            channel: 'sms',
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
            requireProvider: false,
        ));
    }
}