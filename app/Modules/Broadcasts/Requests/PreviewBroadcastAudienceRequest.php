<?php

namespace App\Modules\Broadcasts\Requests;

use App\Modules\Core\Requests\Concerns\NormalizesContactFilter;
use Illuminate\Foundation\Http\FormRequest;

class PreviewBroadcastAudienceRequest extends FormRequest
{
    use NormalizesContactFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->contactFilterRules(
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
            criteriaField: 'recipient_criteria',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientFilter(): array
    {
        return $this->contactFilterAttributes(
            validated: $this->validated(),
            typeField: 'recipient_filter_type',
            tagField: 'recipient_tag',
            idsField: 'contact_ids',
            criteriaField: 'recipient_criteria',
        );
    }
}