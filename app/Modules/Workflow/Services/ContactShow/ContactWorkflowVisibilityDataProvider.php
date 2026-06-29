<?php

namespace App\Modules\Workflow\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Str;

class ContactWorkflowVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contact->loadMissing([
            'workflowProfile.contactStatus',
            'workflowProfile.assignedTo',
        ]);

        $profile = $contact->workflowProfile;

        return [
            'contactVisibilitySections' => [
                'workflow' => [
                    'title' => 'Workflow',
                    'description' => 'Current workflow/status profile.',
                    'empty' => 'No workflow profile exists for this contact.',
                    'items' => $profile ? [[
                        'title' => $profile->contactStatus?->name ?? 'No current status',
                        'subtitle' => 'Current contact workflow profile',
                        'status' => 'Current',
                        'meta' => [
                            'Status Key' => $profile->contactStatus?->key,
                            'Assigned To' => $this->modelLabel($profile->assignedTo),
                            'Updated' => $this->date($profile->updated_at),
                        ],
                    ]] : [],
                ],
            ],
        ];
    }

    private function modelLabel(mixed $model): ?string
    {
        if (! $model) {
            return null;
        }

        foreach (['name', 'email', 'title'] as $attribute) {
            $value = $model->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return Str::afterLast($model::class, '\\').' #'.$model->getKey();
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }
}