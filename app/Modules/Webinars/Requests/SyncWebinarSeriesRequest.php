<?php

namespace App\Modules\Webinars\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SyncWebinarSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'webinar_series_id' => [
                'nullable',
                'integer',
                'exists:webinar_series,id',
                'required_without:webinar_series_variant_id',
            ],
            'webinar_series_variant_id' => [
                'nullable',
                'integer',
                'exists:webinar_series_variants,id',
                'required_without:webinar_series_id',
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('webinar_series_id') && $this->filled('webinar_series_variant_id')) {
                    $validator->errors()->add(
                        'webinar_series_variant_id',
                        'Choose either a webinar series or one market to sync, not both.',
                    );
                }
            },
        ];
    }
}