<?php

namespace Tests\Feature\ConfigGeneration;

use Tests\TestCase;

class CoreWebinarMessagingDefaultsTest extends TestCase
{
    public function test_core_webinar_defaults_are_generic_and_intentionally_small(): void
    {
        $configs = [
            'email_transactional' => require base_path('config/messaging/email/definitions/transactional/webinar.php'),
            'sms_transactional' => require base_path('config/messaging/sms/definitions/transactional/webinar.php'),
            'email_waitlist' => require base_path('config/messaging/email/definitions/marketing/webinar_waitlist.php'),
            'sms_waitlist' => require base_path('config/messaging/sms/definitions/marketing/webinar_waitlist.php'),
            'email_nurture' => require base_path('config/messaging/email/definitions/marketing/webinar_nurture.php'),
            'sms_nurture' => require base_path('config/messaging/sms/definitions/marketing/webinar_nurture.php'),
            'campaigns' => require base_path('config/presets/modules/webinars/campaigns.php'),
        ];

        $serialized = strtolower(json_encode(
            $configs,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '');

        foreach ([
            'slam dunk',
            'stacey',
            'mortgage',
            'homebuyer',
            'pre-approval',
            'preapproval',
            'lender',
            'loan',
        ] as $forbiddenPhrase) {
            $this->assertStringNotContainsString(
                $forbiddenPhrase,
                $serialized,
                "Core Webinar defaults contain client- or vertical-specific phrase [{$forbiddenPhrase}].",
            );
        }

        $this->assertSame(
            [
                'reminder_1_week',
                'reminder_1_day',
                'reminder_30_minute',
                'reminder_live',
            ],
            array_column($configs['email_transactional']['reminders'], 'key'),
        );

        $this->assertSame(
            [
                'reminder_1_week',
                'reminder_1_day',
                'reminder_30_minute',
                'reminder_live',
            ],
            array_column($configs['sms_transactional']['reminders'], 'key'),
        );

        $this->assertSame(
            [
                'webinar_attended_nurture',
                'webinar_missed_nurture',
            ],
            array_keys($configs['sms_nurture']['campaigns']),
        );

        foreach ([
            'email_transactional',
            'sms_transactional',
            'email_waitlist',
            'sms_waitlist',
            'email_nurture',
            'sms_nurture',
        ] as $configKey) {
            $this->assertArrayNotHasKey(
                'opt_ins',
                $configs[$configKey],
                "Scope-specific opt-in copy must not return to Messaging definition config [{$configKey}].",
            );
        }
    }

    public function test_core_webinar_campaign_defaults_are_one_step_email_follow_ups_after_seven_days(): void
    {
        $campaigns = require base_path('config/presets/modules/webinars/campaigns.php');
        $emailTemplates = require base_path('config/messaging/email/definitions/marketing/webinar_nurture.php');

        foreach ([
            'webinar_attended_nurture',
            'webinar_missed_nurture',
        ] as $campaignKey) {
            $definition = $campaigns['definitions'][$campaignKey] ?? null;

            $this->assertIsArray($definition);
            $this->assertCount(1, $definition['steps']);

            $step = $definition['steps'][0];

            $this->assertSame(1, $step['step_number']);
            $this->assertSame(
                [
                    'type' => 'delay',
                    'days' => 7,
                ],
                $step['criteria']['timing'],
            );

            $this->assertCount(2, $step['variants']);

            $this->assertSame(
                ['sms', 'email'],
                array_column($step['variants'], 'key'),
            );

            $this->assertSame(
                ['sms', 'email'],
                array_column($step['variants'], 'channel'),
            );

            foreach ($step['variants'] as $variant) {
                $this->assertSame('marketing', $variant['purpose']);
                $this->assertSame('webinar_nurture', $variant['scope']);
            }

            $this->assertSame('dependency_aware', $step['variant_strategy']);

            $this->assertIsArray(
                data_get($emailTemplates, "campaigns.{$campaignKey}.steps.1.variants.email"),
                "Core campaign [{$campaignKey}] is missing its matching Messaging template.",
            );
        }
    }

    public function test_core_webinar_schedule_profile_matches_the_simplified_definition_keys(): void
    {
        $profiles = require base_path('config/webinars/schedule_profiles.php');
        $profile = $profiles['full_10_day'] ?? null;

        $this->assertIsArray($profile);
        $this->assertTrue($profile['is_default']);
        $this->assertTrue($profile['is_active']);
        $this->assertCount(16, $profile['items']);

        $reminderItems = collect($profile['items'])
            ->where('context_key', 'reminders')
            ->groupBy('channel');

        foreach (['email', 'sms'] as $channel) {
            $items = $reminderItems->get($channel, collect());

            $this->assertSame(
                [
                    'reminder_1_week',
                    'reminder_1_day',
                    'reminder_30_minute',
                    'reminder_live',
                ],
                $items->pluck('message_template_key')->values()->all(),
            );

            $live = $items->firstWhere('message_template_key', 'reminder_live');

            $this->assertIsArray($live);
            $this->assertSame(
                [
                    'type' => 'anchored',
                    'minutes' => 0,
                ],
                $live['schedule'],
            );
            $this->assertTrue((bool) data_get($live, 'meta.skip_when_join_clicked'));
        }
    }
}
