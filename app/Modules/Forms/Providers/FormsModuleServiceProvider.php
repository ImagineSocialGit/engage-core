<?php

namespace App\Modules\Forms\Providers;

use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Modules\Forms\Contracts\HostedFormContextResolver;
use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContract;
use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContractTargetProvider;
use App\Modules\Forms\Deployment\FormsCallerConfiguration;
use App\Modules\Forms\Deployment\FormsCredentialRuntimeContributor;
use App\Modules\Forms\Deployment\FormsDeploymentPlanContributor;
use App\Modules\Forms\HumanVerification\FormsPublicHumanVerificationSurface;
use App\Modules\Forms\Services\HostedFormContextRegistry;
use App\Modules\Forms\Validation\FormsSetupValidationContributor;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeContributor;
use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use App\Support\HumanVerification\Contracts\RequiredPublicHumanVerificationSurface;
use Illuminate\Support\ServiceProvider;

class FormsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $managed = FormsCallerConfiguration::settings(
            CoreInternalCallerRegistry::selected(),
            config('forms.external_intake.authorized_callers', []),
        );
        // Only registered AND explicitly authorized callers become active.
        config()->set('forms.external_intake.clients', $managed);
        if ($managed !== []) {
            config()->set('forms.external_intake.enabled', true);
        }
        $this->app->tag(
            [FormsCredentialRuntimeContributor::class],
            CoreCredentialRuntimeContributor::TAG,
        );

        $this->app->singleton(
            HostedFormContextRegistry::class,
            fn ($app): HostedFormContextRegistry => new HostedFormContextRegistry(
                $app->tagged(HostedFormContextResolver::TAG),
            ),
        );

        // Only the Forms module declares its mandatory browser-facing boundary.
        // Signed, authenticated machine-to-machine intake remains distinct.
        $this->app->tag(
            [FormsPublicHumanVerificationSurface::class],
            RequiredPublicHumanVerificationSurface::TAG,
        );

        $this->app->tag([
            FormSubmissionAutomationTriggerAuthoringContributor::class,
        ], 'automation.trigger_authoring_contributors');

        $this->app->tag(
            FormsSetupValidationContributor::class,
            'setup.validation_contributors',
        );

        $this->app->tag(
            FormsDeploymentPlanContributor::class,
            'deployment.plan_contributors',
        );

        $this->app->tag(
            FormDefinitionConfigContract::class,
            'config.contracts',
        );

        $this->app->tag(
            FormDefinitionConfigContractTargetProvider::class,
            'config.contract_target_providers',
        );
    }

}
