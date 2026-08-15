<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebinarWaitlistSignup>
 */
class WebinarWaitlistSignupFactory extends Factory
{
    protected $model = WebinarWaitlistSignup::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'webinar_series_id' => WebinarSeries::factory(),
            'notified_at' => null,
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE,
            'expires_at' => null,
            'ended_at' => null,
            'source_page' => 'webinar-notify-me',
            'meta' => [
                'accepted_channels' => [
                    'marketing' => [
                        'email',
                    ],
                ],
            ],
        ];
    }

    public function withSms(): self
    {
        return $this->state(fn (): array => [
            'meta' => [
                'accepted_channels' => [
                    'marketing' => [
                        'email',
                        'sms',
                    ],
                ],
            ],
        ]);
    }

    public function notified(): self
    {
        return $this->state(fn (): array => [
            'notified_at' => now(),
        ]);
    }

    public function recurring(int $durationDays = 365): self
    {
        return $this->state(fn (): array => [
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->addDays(max(1, $durationDays)),
            'ended_at' => null,
        ]);
    }
}