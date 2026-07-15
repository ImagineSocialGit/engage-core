<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\Presets\PresetPackageResolver;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SlamDunkConfigGoldenFixtureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private static array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->setBootstrapEnvironment('CLIENT_KEY', 'slam-dunk-crm');

        return parent::createApplication();
    }

    public function test_real_client_boot_produces_the_frozen_slam_dunk_effective_configuration(): void
    {
        $this->assertSame('slam-dunk-crm', config('client.key'));
        $this->assertSame('mortgage', config('client.preset'));
        $this->assertSame('Slam Dunk Home Loans', config('client.name'));
        $this->assertSame('America/New_York', config('client.timezone'));

        $this->assertSame([
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
            'mortgage',
        ], config('modules.enabled'));

        $package = config('presets.packages.mortgage');

        $this->assertIsArray($package);
        $this->assertSame([
            'contact_statuses' => ['slam_dunk_crm_default', 'webinar_default'],
            'tasks' => ['general_default', 'webinar_default'],
            'flow_routes' => ['webinar_default'],
            'campaigns' => ['webinar_default'],
        ], $package['groups'] ?? null);

        $this->assertSame([
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
            'mortgage',
        ], $package['modules']['enabled'] ?? null);

        $this->assertSame([
            'dashboard',
            'core',
            ...$package['modules']['enabled'],
        ], app(PresetPackageResolver::class)->effectiveModules('mortgage'));

        $attendedSteps = config(
            'presets.modules.webinars.campaigns.definitions.webinar_attended_nurture.steps'
        );
        $missedSteps = config(
            'presets.modules.webinars.campaigns.definitions.webinar_missed_nurture.steps'
        );

        $this->assertIsArray($attendedSteps);
        $this->assertIsArray($missedSteps);
        $this->assertSame(range(1, 9), array_column($attendedSteps, 'step_number'));
        $this->assertSame(range(1, 19), array_column($missedSteps, 'step_number'));

        $this->assertCount(6, config('messaging.email.definitions.transactional.webinar.reminders'));
        $this->assertCount(6, config('messaging.sms.definitions.transactional.webinar.reminders'));
        $this->assertCount(20, config('webinars.schedule_profiles.full_10_day.items'));

        $this->assertStringContainsString(
            'Stacey',
            (string) config('messaging.email.definitions.transactional.webinar.confirmations.0.payload.body'),
        );
        $this->assertStringContainsString(
            'mortgage game plan',
            strtolower((string) config('messaging.email.definitions.transactional.webinar.confirmations.0.payload.body')),
        );
        $this->assertStringContainsString(
            'Stacey',
            (string) config('messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email.payload.body'),
        );
    }

    public function test_slam_dunk_package_syncs_cleanly_into_a_fresh_database_and_resolves_all_selected_runtime_records(): void
    {
        $exitCode = Artisan::call('presets:sync', [
            'preset' => 'mortgage',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertSame([
            'attended_webinar',
            'closed_funded',
            'fallout_inactive',
            'in_process',
            'missed_webinar',
            'new',
            'prospect',
            'registered',
        ], ContactStatus::query()->orderBy('key')->pluck('key')->all());

        $this->assertSame([
            'general.follow_up',
            'general.review',
            'general.waiting_on_contact',
            'general.waiting_on_third_party',
            'webinar.call_high_intent_contact',
            'webinar.review_reply',
        ], TaskTemplate::query()->orderBy('key')->pluck('key')->all());

        $this->assertSame([
            'webinar_attended_nurture',
            'webinar_missed_nurture',
        ], Campaign::query()->orderBy('key')->pluck('key')->all());

        $this->assertSame(9, $this->campaignStepCount('webinar_attended_nurture'));
        $this->assertSame(19, $this->campaignStepCount('webinar_missed_nurture'));
        $this->assertSame(9, $this->campaignVariantCount('webinar_attended_nurture'));
        $this->assertSame(24, $this->campaignVariantCount('webinar_missed_nurture'));

        $this->assertSame([
            'webinar_attended_campaign_enrollment',
            'webinar_attended_status_transition',
            'webinar_missed_campaign_enrollment',
            'webinar_missed_status_transition',
        ], FlowRoute::query()->orderBy('key')->pluck('key')->all());

        $this->assertSame(4, FlowRoutePoint::query()->count());
        $this->assertSame(4, FlowRouteTriggerBinding::query()->count());

        $this->assertSame(
            ['full_10_day'],
            WebinarScheduleProfile::query()->orderBy('key')->pluck('key')->all(),
        );
        $this->assertSame(20, WebinarScheduleProfileItem::query()->count());

        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'email.transactional.webinar.confirmation',
            'message_type' => 'confirmation',
        ]);
        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'sms.transactional.webinar.confirmation',
            'message_type' => 'confirmation',
        ]);
        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            'message_type' => 'webinar_attended_nurture_step_1',
        ]);
        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'sms.marketing.webinar_nurture.campaigns.webinar_missed_nurture.steps.1.variants.sms',
            'message_type' => 'webinar_missed_nurture_step_1',
        ]);

        $validation = app(SetupValidationManager::class)->validate();

        $this->assertFalse(
            $validation->hasErrors(),
            json_encode($validation->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Setup validation failed.',
        );
    }

    public function test_slam_dunk_foundational_config_matches_registered_closed_contracts(): void
    {
        $registry = app(ConfigContractRegistry::class);

        $this->assertSame([], $registry->get('app.preset_package')->schema()->validate(
            config('presets.packages.mortgage'),
            'presets.packages.mortgage',
        ));

        foreach (config('presets.modules.client.contact-statuses.definitions', []) as $key => $definition) {
            $this->assertSame(
                [],
                $registry->get('core.contact_status_definition')->schema()->validate(
                    $definition,
                    "presets.modules.client.contact-statuses.definitions.{$key}",
                ),
            );
        }

        foreach (config('presets.modules.webinars.contact-statuses.definitions', []) as $key => $definition) {
            $this->assertSame(
                [],
                $registry->get('core.contact_status_definition')->schema()->validate(
                    $definition,
                    "presets.modules.webinars.contact-statuses.definitions.{$key}",
                ),
            );
        }

        foreach (config('presets.modules.webinars.tasks.definitions', []) as $key => $definition) {
            $this->assertSame(
                [],
                $registry->get('tasks.preset_definition')->schema()->validate(
                    $definition,
                    "presets.modules.webinars.tasks.definitions.{$key}",
                ),
            );
        }

        foreach (config('presets.modules.webinars.campaigns.definitions', []) as $key => $definition) {
            $this->assertSame([], $registry->get('campaigns.preset_definition')->schema()->validate(
                $definition,
                "presets.modules.webinars.campaigns.definitions.{$key}",
            ));
        }

        foreach (config('presets.modules.webinars.flow-routes.definitions', []) as $key => $definition) {
            $this->assertSame([], $registry->get('flow_routes.preset_definition')->schema()->validate(
                $definition,
                "presets.modules.webinars.flow-routes.definitions.{$key}",
            ));
        }

        foreach (config('webinars.schedule_profiles', []) as $key => $profile) {
            $this->assertSame([], $registry->get('webinars.schedule_profile')->schema()->validate(
                $profile,
                "webinars.schedule_profiles.{$key}",
            ));
        }

        $this->assertSame([], $registry->get('webinars.post_event')->schema()->validate(
            config('webinars.post_event'),
            'webinars.post_event',
        ));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreBootstrapEnvironment();
    }

    private function campaignStepCount(string $campaignKey): int
    {
        return CampaignStep::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_steps.campaign_id')
            ->where('campaigns.key', $campaignKey)
            ->count();
    }

    private function campaignVariantCount(string $campaignKey): int
    {
        return CampaignStepVariant::query()
            ->join('campaign_steps', 'campaign_steps.id', '=', 'campaign_step_variants.campaign_step_id')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_steps.campaign_id')
            ->where('campaigns.key', $campaignKey)
            ->count();
    }

    private function setBootstrapEnvironment(string $key, string $value): void
    {
        if (! array_key_exists($key, self::$originalEnvironment)) {
            self::$originalEnvironment[$key] = [
                'environment' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreBootstrapEnvironment(): void
    {
        foreach (self::$originalEnvironment as $key => $original) {
            $original['environment'] === false
                ? putenv($key)
                : putenv("{$key}={$original['environment']}");

            if ($original['env_exists']) {
                $_ENV[$key] = $original['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($original['server_exists']) {
                $_SERVER[$key] = $original['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        self::$originalEnvironment = [];
    }
}
