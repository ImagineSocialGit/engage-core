<?php

namespace Tests\Feature\ConfigContracts;

use App\Modules\Messaging\ConfigContracts\MessagingConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use Tests\TestCase;

class MessagingConfigContractTargetProviderTest extends TestCase
{
    public function test_it_preserves_untouched_authored_values_for_standard_and_campaign_targets(): void
    {
        $provider = new MessagingConfigContractTargetProvider;
        $context = ConfigContractTargetContext::proposed([
            'messaging' => [
                'email' => [
                    'marketing' => [
                        'newsletter' => [
                            'welcome' => [
                                'dispatch_key' => 'welcome',
                                'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
                                'queue' => 'marketing_messages',
                                'payload' => [
                                    'subject' => 'Welcome',
                                    'body' => 'Hello {first_name}',
                                ],
                                'invented_field' => 'must_survive_discovery',
                            ],
                            'campaigns' => [
                                'nurture' => [
                                    'steps' => [
                                        1 => [
                                            'variants' => [
                                                'email' => [
                                                    'dispatch_key' => 'campaign_step_due',
                                                    'payload_class' => 'App\\Modules\\Messaging\\Payloads\\EmailPayload',
                                                    'queue' => 'campaign_messages',
                                                    'payload' => [
                                                        'subject' => 'Follow up',
                                                        'body' => 'Hello again',
                                                    ],
                                                    'invented_campaign_field' => true,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'sms' => [],
                'permission_invitations' => [],
            ],
        ]);

        $targets = collect($provider->targets($context))->keyBy('path');

        $standard = $targets->get('messaging.email.marketing.newsletter.welcome');
        $campaign = $targets->get('messaging.email.marketing.newsletter.campaigns.nurture.steps.1.variants.email');

        $this->assertNotNull($standard);
        $this->assertNotNull($campaign);
        $this->assertSame('must_survive_discovery', $standard->value['invented_field']);
        $this->assertTrue($campaign->value['invented_campaign_field']);
        $this->assertArrayNotHasKey('config_path', $standard->value);
        $this->assertArrayNotHasKey('campaign_key', $campaign->value);
    }

    public function test_it_traverses_list_backed_standard_message_definitions_without_normalizing_them(): void
    {
        $provider = new MessagingConfigContractTargetProvider;
        $context = ConfigContractTargetContext::proposed([
            'messaging' => [
                'email' => [
                    'transactional' => [
                        'webinar' => [
                            'reminder' => [
                                [
                                    'dispatch_key' => 'reminder_one',
                                    'payload_class' => 'FirstPayload',
                                    'queue' => 'messages',
                                    'payload' => ['subject' => 'One', 'body' => 'One'],
                                ],
                                [
                                    'dispatch_key' => 'reminder_two',
                                    'payload_class' => 'SecondPayload',
                                    'queue' => 'messages',
                                    'payload' => ['subject' => 'Two', 'body' => 'Two'],
                                ],
                            ],
                        ],
                    ],
                ],
                'sms' => [],
                'permission_invitations' => [],
            ],
        ]);

        $targets = collect($provider->targets($context));

        $this->assertNotNull($targets->firstWhere(
            'path',
            'messaging.email.transactional.webinar.reminder.0',
        ));
        $this->assertNotNull($targets->firstWhere(
            'path',
            'messaging.email.transactional.webinar.reminder.1',
        ));
    }
}
