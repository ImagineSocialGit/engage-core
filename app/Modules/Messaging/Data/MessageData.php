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
        $firstName = $this->stringValue($this->contact->first_name, 'there');
        $lastName = $this->stringValue($this->contact->last_name);
        $name = $this->stringValue($this->contact->name);
        $email = $this->stringValue($this->contact->email);
        $phone = $this->stringValue($this->contact->phone);

        if ($name === '') {
            $name = trim(implode(' ', array_filter([$firstName !== 'there' ? $firstName : null, $lastName])));
        }

        $contact = [
            'id' => $this->contact->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];

        return [
            'contact' => $contact,
            'contact_id' => $this->contact->getKey(),

            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,

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
        return $date?->copy()->setTimezone($timezone)->format('F j, Y \\a\\t g:i A T');
    }

    private function stringValue(mixed $value, string $fallback = ''): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $fallback;
    }
}
