<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use Tests\TestCase;

class MessageDefinitionConfigPathTest extends TestCase
{
    public function test_it_builds_current_canonical_definition_paths_consistently(): void
    {
        $this->assertSame(
            'messaging.email',
            MessageDefinitionConfigPath::definitionsRoot('email'),
        );

        $this->assertSame(
            'messaging.email.transactional',
            MessageDefinitionConfigPath::purpose('email', 'transactional'),
        );

        $this->assertSame(
            'messaging.email.transactional.webinar',
            MessageDefinitionConfigPath::scope('email', 'transactional', 'webinar'),
        );

        $this->assertSame(
            'messaging.email.transactional.webinar.reminders.0',
            MessageDefinitionConfigPath::definition('email', 'transactional', 'webinar', 'reminders', 0),
        );

        $this->assertSame(
            'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            MessageDefinitionConfigPath::campaignVariant(
                channel: 'email',
                purpose: 'marketing',
                scope: 'webinar_nurture',
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email',
            ),
        );

        $this->assertSame(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            MessageDefinitionConfigPath::payloadField(
                channel: 'email',
                purpose: 'transactional',
                scope: 'webinar',
                messageType: 'confirmation',
                field: 'subject',
            ),
        );
    }
}
