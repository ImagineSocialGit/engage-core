<?php

namespace Tests\Feature\SetupValidation;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Modules\Scheduling\Validation\SchedulingSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SchedulingSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;
    public function test_missing_public_url_is_valid_because_public_booking_is_optional(): void
    {
        config()->set('scheduling.public.configured', false);
        config()->set('scheduling.public.enabled', false);
        config()->set('scheduling.public.url', null);
        config()->set('scheduling.public.host', null);
        config()->set('scheduling.public.scheme', null);

        $this->assertEquals([], $this->findings());
    }

    public function test_malformed_configured_public_url_is_reported(): void
    {
        config()->set('scheduling.public.configured', true);
        config()->set('scheduling.public.enabled', false);
        config()->set('scheduling.public.url', null);
        config()->set('scheduling.public.host', null);
        config()->set('scheduling.public.scheme', null);

        $finding = $this->findings()[0] ?? null;

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding['severity']);
        $this->assertSame('scheduling.public_app_url_invalid', $finding['code']);
        $this->assertSame('scheduling', $finding['module']);
        $this->assertSame('scheduling.public', $finding['source']);
        $this->assertSame('scheduling.public.url', $finding['path']);
    }

    public function test_valid_configured_public_url_has_no_findings(): void
    {
        config()->set('scheduling.public.configured', true);
        config()->set('scheduling.public.enabled', true);
        config()->set('scheduling.public.url', 'https://booking.example.test');
        config()->set('scheduling.public.host', 'booking.example.test');
        config()->set('scheduling.public.scheme', 'https');

        $this->assertEquals([], $this->findings());
    }

    public function test_active_manual_host_without_user_identity_is_reported(): void
    {
        $this->disablePublicBooking();

        $host = SchedulingHost::factory()->create([
            'source' => SchedulingHost::SOURCE_MANUAL,
            'status' => SchedulingHost::STATUS_ACTIVE,
            'hostable_type' => null,
            'hostable_id' => null,
        ]);

        $finding = collect($this->findings())
            ->firstWhere('code', 'scheduling.staff_user_identity_missing');

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding['severity']);
        $this->assertSame('scheduling.staff', $finding['source']);
        $this->assertSame(
            'scheduling_hosts.'.$host->getKey().'.hostable',
            $finding['path'],
        );
    }

    public function test_active_user_backed_host_has_no_staff_identity_finding(): void
    {
        $this->disablePublicBooking();

        $user = User::factory()->create();
        SchedulingHost::factory()->forHostable($user)->create([
            'source' => SchedulingHost::SOURCE_MANUAL,
            'status' => SchedulingHost::STATUS_ACTIVE,
        ]);

        $codes = collect($this->findings())->pluck('code')->all();

        $this->assertNotContains('scheduling.staff_user_identity_missing', $codes);
        $this->assertNotContains('scheduling.staff_user_identity_unavailable', $codes);
    }

    public function test_active_host_linked_to_inactive_crm_user_is_reported(): void
    {
        $this->disablePublicBooking();

        $user = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => false,
        ]);
        SchedulingHost::factory()->forHostable($user)->create([
            'source' => SchedulingHost::SOURCE_MANUAL,
            'status' => SchedulingHost::STATUS_ACTIVE,
        ]);

        $finding = collect($this->findings())
            ->firstWhere('code', 'scheduling.staff_user_identity_unavailable');

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding['severity']);
    }

    public function test_scheduling_provider_registers_setup_validation_contributor(): void
    {
        $this->app->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('setup.validation_contributors'),
                false,
            ),
        );

        $this->assertContains(
            SchedulingSetupValidationContributor::class,
            $classes,
        );
    }

    public function test_setup_validate_surfaces_invalid_public_url(): void
    {
        config()->set('scheduling.public.configured', true);
        config()->set('scheduling.public.enabled', false);
        config()->set('scheduling.public.url', null);
        config()->set('scheduling.public.host', null);
        config()->set('scheduling.public.scheme', null);

        $this->app->instance(
            SetupValidationManager::class,
            new SetupValidationManager([
                app(SchedulingSetupValidationContributor::class),
            ]),
        );

        $this->assertSame(1, Artisan::call('setup:validate'));

        $output = Artisan::output();

        $this->assertStringContainsString(
            'scheduling.public_app_url_invalid',
            $output,
        );
        $this->assertStringContainsString(
            '[scheduling | scheduling.public | scheduling.public.url]',
            $output,
        );
    }

    private function disablePublicBooking(): void
    {
        config()->set('scheduling.public.configured', false);
        config()->set('scheduling.public.enabled', false);
        config()->set('scheduling.public.url', null);
        config()->set('scheduling.public.host', null);
        config()->set('scheduling.public.scheme', null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            static fn ($finding): array => $finding->toArray(),
            iterator_to_array(
                app(SchedulingSetupValidationContributor::class)
                    ->findings(),
                false,
            ),
        );
    }
}