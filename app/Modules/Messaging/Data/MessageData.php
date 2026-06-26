<?php

namespace App\Modules\Messaging\Data;

use App\Modules\Core\Models\Contact;
use Carbon\CarbonInterface;

readonly class MessageData
{
    public function __construct(
        public Contact $contact,
        public ?string $requestIp = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contact' => $this->contact->toArray(),

            'contact_id' => $this->contact->getKey(),
            'contact_first_name' => $this->contact->first_name ?? 'there',
            'contact_last_name' => $this->contact->last_name,
            'contact_full_name' => $this->contact->name,
            'contact_email' => $this->contact->email,
            'contact_phone' => $this->contact->phone,

            'first_name' => $this->contact->first_name ?? 'there',
            'last_name' => $this->contact->last_name,
            'full_name' => $this->contact->name,
            'email' => $this->contact->email,
            'phone' => $this->contact->phone,

            'request_ip' => $this->requestIp,
        ];
    }

    public function contact(): Contact
    {
        return $this->contact;
    }

    protected function formatDate(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('F j, Y');
    }

    protected function formatTime(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('g:i A T');
    }

    protected function formatDateTime(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('F j, Y \a\t g:i A T');
    }
}