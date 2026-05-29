<?php

namespace App\Http\Requests\CRM;

use Illuminate\Foundation\Http\FormRequest;

class SyncWebinarSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'webinar_series_id' => ['required', 'integer', 'exists:webinar_series,id'],
        ];
    }
}
