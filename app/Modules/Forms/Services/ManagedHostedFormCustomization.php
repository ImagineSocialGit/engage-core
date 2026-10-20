<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Models\FormContextVersion;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded, context-specific editing of existing public hosted Forms.
 *
 * The definition's current version remains shared. Each context binding points
 * to its own published snapshot, without changing the original preset.
 */
final class ManagedHostedFormCustomization
{
    private const VERSION_SOURCE = 'managed_context';

    public function __construct(
        private readonly PublishedFormResolver $forms,
        private readonly FormSchemaNormalizer $schemas,
        private readonly HostedFormRuntimeValidator $runtime,
        private readonly HostedFormLayoutResolver $layouts,
        private readonly FormSubmissionConsentIntentResolver $consents,
    ) {}

    /**
     * @return array{form_key:string,version_id:int,base_version_id:int,collect:array<int,array<string,mixed>>,permissions:array<int,array<string,mixed>>}
     */
    public function editor(string $formKey, HostedFormContextReference $context): array
    {
        $base = $this->forms->require($formKey, publicOnly: true);
        $published = $this->forms->requireForContext($formKey, $context, publicOnly: true);
        $this->assertHosted($base);
        $this->assertHosted($published);

        $permissionFields = [];

        foreach ($this->consents->resolve($base) as $intent) {
            $permissionFields[$intent->field][] = [
                'channel' => $intent->channel,
                'purpose' => $intent->purpose,
            ];
        }

        $protected = $this->visibilityDependencies($base);
        $collect = [];
        $permissions = [];

        foreach ($base->fields as $field) {
            $key = (string) $field['key'];

            if (($field['type'] ?? null) === 'hidden') {
                continue;
            }

            $selected = $published->field($key);
            $baseRequired = (bool) ($field['required'] ?? false);
            $isPermission = isset($permissionFields[$key]);
            $entry = [
                'key' => $key,
                'label' => (string) $field['label'],
                'type' => (string) $field['type'],
                'visible' => ($selected['managed_visible'] ?? true) !== false,
                'required' => (bool) ($selected['required'] ?? $baseRequired),
                'can_hide' => ! $baseRequired && ! isset($protected[$key]),
                'can_change_required' => ! $baseRequired && ! $isPermission,
            ];

            if ($isPermission) {
                $permissions[] = [
                    ...$entry,
                    'intents' => $permissionFields[$key],
                ];
            } else {
                $collect[] = $entry;
            }
        }

        return [
            'form_key' => $base->key,
            'version_id' => $published->versionId,
            'base_version_id' => $base->versionId,
            'collect' => $collect,
            'permissions' => $permissions,
        ];
    }

    /**
     * A complete selection keyed by field key, with strict boolean values:
     * ['email' => ['visible' => true, 'required' => true], ...].
     *
     * @param array<string, array{visible:bool,required:bool}> $fields
     */
    public function publish(
        string $formKey,
        HostedFormContextReference $context,
        int $expectedVersionId,
        array $fields,
    ): PublishedForm {
        if ($expectedVersionId < 1) {
            throw new InvalidArgumentException('An existing published FormVersion is required.');
        }

        DB::transaction(function () use ($formKey, $context, $expectedVersionId, $fields): void {
            $definition = FormDefinition::query()
                ->where('key', $formKey)
                ->where('status', FormDefinition::STATUS_ACTIVE)
                ->where('is_public', true)
                ->lockForUpdate()
                ->first();

            if (! $definition instanceof FormDefinition) {
                throw new DomainException("Published form [{$formKey}] is unavailable.");
            }

            $state = $this->editor($formKey, $context);

            if ($state['version_id'] !== $expectedVersionId) {
                throw new DomainException('This Form has changed. Reload the editor before saving.');
            }

            $supported = [];

            foreach (array_merge($state['collect'], $state['permissions']) as $entry) {
                $supported[$entry['key']] = $entry;
            }

            $receivedKeys = array_keys($fields);
            $supportedKeys = array_keys($supported);
            sort($receivedKeys);
            sort($supportedKeys);

            if ($receivedKeys !== $supportedKeys) {
                throw new InvalidArgumentException('The Form selection must include exactly the supported fields.');
            }

            foreach ($fields as $key => $selection) {
                if (! is_array($selection)
                    || count($selection) !== 2
                    || ! array_key_exists('visible', $selection)
                    || ! array_key_exists('required', $selection)
                    || ! is_bool($selection['visible'])
                    || ! is_bool($selection['required'])
                ) {
                    throw new InvalidArgumentException("Form field [{$key}] requires boolean visible and required settings.");
                }

                $entry = $supported[$key];

                if ((! $entry['can_hide'] && ! $selection['visible'])
                    || (! $entry['can_change_required'] && $selection['required'] !== $entry['required'])
                    || (! $selection['visible'] && $selection['required'])
                ) {
                    throw new InvalidArgumentException("Form field [{$key}] cannot use the requested visibility or requiredness.");
                }
            }

            $base = $this->forms->require($formKey, publicOnly: true);
            $selected = $this->forms->requireForContext($formKey, $context, publicOnly: true);
            $schema = $base->schema;

            foreach ($schema['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    $key = (string) $field['key'];

                    if (! isset($fields[$key])) {
                        continue;
                    }

                    $field['required'] = $fields[$key]['required'];
                    unset($field['managed_visible']);

                    if (! $fields[$key]['visible']) {
                        $field['managed_visible'] = false;
                    }
                }
                unset($field);
            }
            unset($section);

            $schema = $this->schemas->normalize($schema);
            $candidate = new PublishedForm(
                definitionId: $base->definitionId,
                versionId: $base->versionId,
                versionNumber: $base->versionNumber,
                key: $base->key,
                name: $base->name,
                description: $base->description,
                category: $base->category,
                isPublic: $base->isPublic,
                schema: $schema,
                rules: $base->rules,
                layout: $base->layout,
                settings: $base->settings,
                fields: $this->schemas->fields($schema),
            );
            $this->assertHosted($candidate);

            // Re-saving the same selections never creates a redundant snapshot.
            if ($candidate->schema === $selected->schema
                && $candidate->rules === $selected->rules
                && $candidate->layout === $selected->layout
                && $candidate->settings === $selected->settings
            ) {
                return;
            }

            $next = ((int) $definition->versions()->withTrashed()->max('version')) + 1;
            $published = $definition->versions()->create([
                'version' => $next,
                'status' => FormVersion::STATUS_PUBLISHED,
                'name' => $candidate->name,
                'description' => $candidate->description,
                'schema' => $candidate->schema,
                'rules' => $candidate->rules,
                'layout' => $candidate->layout,
                'settings' => $candidate->settings,
                'published_at' => now(),
                'archived_at' => null,
                'source' => self::VERSION_SOURCE,
                'provider' => null,
                'external_id' => null,
                'meta' => [
                    'base_version_id' => $base->versionId,
                    'context' => $context->toArray(),
                ],
            ]);

            FormContextVersion::query()->updateOrCreate(
                [
                    'form_definition_id' => $definition->getKey(),
                    'context_key' => $context->key,
                    'context_reference' => $context->reference,
                ],
                ['form_version_id' => $published->getKey()],
            );
        }, attempts: 3);

        return $this->forms->requireForContext($formKey, $context, publicOnly: true);
    }

    /** @return array<string, bool> */
    private function visibilityDependencies(PublishedForm $form): array
    {
        $protected = [];

        foreach ($form->layout['conditions'] ?? [] as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            foreach ($condition['when'] ?? [] as $predicate) {
                if (is_array($predicate) && is_string($predicate['field'] ?? null)) {
                    $protected[$predicate['field']] = true;
                }
            }
        }

        $contactFields = data_get($form->settings, 'submission.contact.fields', []);

        if (is_array($contactFields) && is_string($contactFields['email'] ?? null)) {
            $protected[$contactFields['email']] = true;
        }

        return $protected;
    }

    private function assertHosted(PublishedForm $form): void
    {
        $this->runtime->validate($form);

        if (! ($this->layouts->resolve($form)['enabled'] ?? false)) {
            throw new DomainException("Published form [{$form->key}] does not have hosted rendering enabled.");
        }
    }
}