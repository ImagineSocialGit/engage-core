<?php

namespace App\Modules\InboundMessaging\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactConversationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reply_body' => ['required', 'string', 'max:10000'],
            'reply_subject' => ['nullable', 'string', 'max:998'],
            'reply_request_key' => [
                'required',
                'string',
                'max:80',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
        ];
    }
}