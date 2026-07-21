<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Models\Webinar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceWebinarOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $source = $this->route('webinar');
        $sourceId = $source instanceof Webinar
            ? (int) $source->getKey()
            : 0;

        return [
            'replacement_webinar_id' => [
                'required',
                'integer',
                Rule::exists('webinars', 'id'),
                Rule::notIn([$sourceId]),
            ],
            'confirm_replacement' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_replacement.accepted' => 'Confirm that the selected occurrence should replace the source occurrence.',
            'replacement_webinar_id.not_in' => 'An occurrence cannot replace itself.',
        ];
    }
}