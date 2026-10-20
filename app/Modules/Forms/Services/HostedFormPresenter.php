<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\PublishedForm;

final class HostedFormPresenter
{
    public function __construct(
        private readonly HostedFormLayoutResolver $layouts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(PublishedForm $form): array
    {
        $layout = $this->layouts->resolve($form);
        $blocks = [];

        foreach ($layout['blocks'] as $block) {
            if ($block['type'] === 'field') {
                $field = $form->field((string) $block['field']);

                if ($field === null || ($field['managed_visible'] ?? true) === false) {
                    continue;
                }

                $blocks[] = [
                    ...$block,
                    'field' => $field,
                ];

                continue;
            }

            $blocks[] = $block;
        }

        $hiddenFields = array_values(array_filter(
            $form->fields,
            static fn (array $field): bool => ($field['type'] ?? null) === 'hidden'
                && ($field['managed_visible'] ?? true) !== false,
        ));

        return [
            'key' => $form->key,
            'slug' => $layout['slug'],
            'name' => $form->name,
            'heading' => $layout['heading'],
            'description' => $layout['description'],
            'enabled' => $layout['enabled'],
            'blocks' => $blocks,
            'hidden_fields' => $hiddenFields,
            'conditions' => $layout['conditions'],
            'submit_label' => $layout['submit_label'],
            'success' => $layout['success'],
            'branding' => $layout['branding'],
        ];
    }
}