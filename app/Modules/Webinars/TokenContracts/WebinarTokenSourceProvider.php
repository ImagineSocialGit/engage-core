<?php

namespace App\Modules\Webinars\TokenContracts;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenSourceDefinition;

class WebinarTokenSourceProvider implements TokenSourceProvider
{
    public function sources(): iterable
    {
        yield from $this->columns('webinar', Webinar::class, ['id', 'webinar_series_id', 'webinar_schedule_profile_id', 'title', 'slug', 'platform', 'external_id', 'host_account_key', 'registration_url', 'playback_url', 'playback_passcode', 'starts_at', 'ends_at', 'timezone', 'description', 'created_at', 'updated_at'], ['title' => ['webinar_title'], 'slug' => ['webinar_slug'], 'timezone' => ['webinar_timezone'], 'registration_url' => ['webinar_registration_url']]);
        yield from $this->columns('webinar_series', WebinarSeries::class, ['id', 'webinar_schedule_profile_id', 'title', 'slug', 'status', 'created_at', 'updated_at'], ['title' => ['webinar_series', 'webinar_series_title'], 'slug' => ['webinar_series_slug']]);
        yield from $this->columns('webinar_registration', WebinarRegistration::class, ['id', 'contact_id', 'webinar_id', 'webinar_slug', 'status', 'source', 'registered_at', 'attended_at', 'cancelled_at', 'created_at', 'updated_at'], ['attended_at' => ['registration_attended_at']]);
        yield from $this->columns('webinar_waitlist_signup', WebinarWaitlistSignup::class, ['id', 'contact_id', 'webinar_series_id', 'source_page', 'notified_at', 'created_at', 'updated_at']);

        $computed = [
            'webinar_join_url' => ['Join link', 'The private link the registrant uses to join the webinar.', 'https://…'],
            'cancel_registration_url' => ['Cancel-registration link', 'A signed link that cancels this webinar registration.', 'https://…'],
            'webinar_playback_url' => ['Replay link', 'The link where the contact can watch the webinar replay.', 'https://…'],
            'webinar_booking_url' => ['Book-a-meeting link', 'The link where the contact can schedule a follow-up meeting.', 'https://…'],
            'webinar_start_date' => ['Webinar date', 'The webinar date formatted for the recipient.', 'September 15, 2026'],
            'webinar_start_time' => ['Webinar start time', 'The webinar start time formatted for the recipient.', '2:00 PM EDT'],
            'webinar_start_datetime' => ['Webinar date and time', 'The webinar date and start time in one friendly value.', 'September 15, 2026 at 2:00 PM EDT'],
            'webinar_end_date' => ['Webinar end date', 'The webinar end date formatted for the recipient.', 'September 15, 2026'],
            'webinar_end_time' => ['Webinar end time', 'The webinar end time formatted for the recipient.', '3:00 PM EDT'],
            'webinar_end_datetime' => ['Webinar end date and time', 'The webinar end date and time in one friendly value.', 'September 15, 2026 at 3:00 PM EDT'],
        ];

        foreach ($computed as $token => [$label, $description, $example]) {
            yield TokenSourceDefinition::computed(
                token: $token,
                owner: 'webinars',
                label: $label,
                description: $description,
                sourcePath: $token,
                providerClass: WebinarMessageTokenValueProvider::class,
                example: $example,
            );
        }
    }

    private function columns(string $prefix, string $model, array $columns, array $aliases = []): iterable
    {
        foreach ($columns as $column) {
            [$label, $description, $example] = $this->presentation("{$prefix}.{$column}");

            yield TokenSourceDefinition::modelColumn(
                token: "{$prefix}.{$column}",
                owner: 'webinars',
                label: $label,
                description: $description,
                modelClass: $model,
                column: $column,
                aliases: $aliases[$column] ?? [],
                example: $example,
            );
        }
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function presentation(string $token): array
    {
        return match ($token) {
            'webinar.title' => ['Webinar title', 'The public title of the webinar.', 'First-Time Homebuyer Workshop'],
            'webinar.platform' => ['Webinar platform', 'The platform hosting the webinar.', 'Zoom'],
            'webinar.registration_url' => ['Registration link', 'The public link where someone can register.', 'https://…'],
            'webinar.description' => ['Webinar description', 'The public description of the webinar.', null],
            'webinar_series.title' => ['Webinar series name', 'The public name of the webinar series.', 'VA Homebuyer Game Plan'],
            default => [str($token)->replace('.', ' ')->headline()->toString(), 'A registered Webinar system field.', null],
        };
    }
}