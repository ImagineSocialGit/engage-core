<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\ExternalFormIntakeClient;
use App\Modules\Forms\Data\PublishedForm;
use App\Support\ModuleIntegrations\Forms\FormSubmissionConsentBridge;
use App\Support\Modules\ModuleManager;
use Throwable;

final class FormsSurfaceReadService
{
    public function __construct(
        private readonly ExternalFormIntakeClientResolver $externalClients,
        private readonly PublishedFormResolver $publishedForms,
        private readonly FormSubmissionValidator $submissions,
        private readonly FormSubmissionContactMapper $contacts,
        private readonly FormSubmissionConsentIntentResolver $consentIntents,
        private readonly FormSubmissionConsentBridge $consentBridge,
        private readonly FormSubmissionVerificationPolicy $verifications,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @return array{
     *     external_intake_enabled: bool,
     *     configuration_valid: bool,
     *     form_count: int,
     *     domain_count: int,
     *     forms: array<int, array{
     *         key: string,
     *         name: string,
     *         description: ?string,
     *         version: int,
     *         domains: array<int, string>,
     *         outcome_keys: array<int, string>
     *     }>
     * }
     */
    public function read(): array
    {
        if (! (bool) config('forms.external_intake.enabled', false)) {
            return $this->result(
                externalIntakeEnabled: false,
                configurationValid: true,
                forms: [],
            );
        }

        try {
            $clients = $this->externalClients->all();
        } catch (Throwable) {
            return $this->result(
                externalIntakeEnabled: true,
                configurationValid: false,
                forms: [],
            );
        }

        $formKeys = collect($clients)
            ->flatMap(fn (ExternalFormIntakeClient $client): array => $client->allowedForms)
            ->unique()
            ->values();
        $forms = [];

        foreach ($formKeys as $formKey) {
            $published = $this->acceptingPublishedForm((string) $formKey);

            if (! $published instanceof PublishedForm) {
                continue;
            }

            $acceptingClients = collect($clients)
                ->filter(fn (ExternalFormIntakeClient $client): bool => $client->allowsForm($published->key));
            $domains = $acceptingClients
                ->flatMap(fn (ExternalFormIntakeClient $client): array => $client->domains)
                ->unique()
                ->sort(
                    static fn (string $left, string $right): int => strnatcasecmp(
                        $left,
                        $right,
                    ),
                )
                ->values()
                ->all();

            $forms[] = [
                'key' => $published->key,
                'name' => $published->name,
                'description' => $published->description,
                'version' => $published->versionNumber,
                'domains' => $domains,
                'outcome_keys' => $this->outcomeKeys($published),
            ];
        }

        usort(
            $forms,
            static fn (array $left, array $right): int => strnatcasecmp(
                $left['name'],
                $right['name'],
            ),
        );

        return $this->result(
            externalIntakeEnabled: true,
            configurationValid: true,
            forms: $forms,
        );
    }

    private function acceptingPublishedForm(string $formKey): ?PublishedForm
    {
        try {
            $form = $this->publishedForms->require(
                key: $formKey,
                publicOnly: true,
            );
            $this->submissions->validateConfiguration($form);
            $this->contacts->validateConfiguration($form);
            $intents = $this->consentIntents->resolve($form);
            $this->consentBridge->validateConfiguration($form, $intents);
            $this->verifications->validateConfiguration($form);

            return $form;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function outcomeKeys(PublishedForm $form): array
    {
        $submission = $form->settings['submission'] ?? [];
        $submission = is_array($submission) ? $submission : [];
        $outcomes = [];

        if (is_array($submission['contact'] ?? null)) {
            $outcomes[] = 'contact_upsert';
        }

        if (is_array($submission['tags'] ?? null)
            && $submission['tags'] !== []
        ) {
            $outcomes[] = 'contact_tags';
        }

        $outcomes[] = 'submission_review';

        if (is_array($submission['consents'] ?? null)
            && $submission['consents'] !== []
            && in_array(
                'messaging',
                $this->modules->enabledKeysWithDependencies(),
                true,
            )
        ) {
            $outcomes[] = 'consent_record';
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $forms
     * @return array<string, mixed>
     */
    private function result(
        bool $externalIntakeEnabled,
        bool $configurationValid,
        array $forms,
    ): array {
        return [
            'external_intake_enabled' => $externalIntakeEnabled,
            'configuration_valid' => $configurationValid,
            'form_count' => count($forms),
            'domain_count' => collect($forms)
                ->flatMap(fn (array $form): array => $form['domains'])
                ->unique()
                ->count(),
            'forms' => $forms,
        ];
    }
}