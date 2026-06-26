<?php

namespace App\Modules\Webinars\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebinarSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:webinar_series,title'],
        ];
    }
}
