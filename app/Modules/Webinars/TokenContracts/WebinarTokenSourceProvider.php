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

        foreach (['webinar_join_url', 'cancel_registration_url', 'webinar_playback_url', 'webinar_waitlist_registration_url', 'webinar_start_date', 'webinar_start_time', 'webinar_start_datetime', 'webinar_end_date', 'webinar_end_time', 'webinar_end_datetime'] as $token) {
            yield TokenSourceDefinition::computed($token, 'webinars', str($token)->headline()->toString(), 'Value explicitly produced by WebinarMessageData.', $token, WebinarMessageTokenValueProvider::class);
        }
    }

    private function columns(string $prefix, string $model, array $columns, array $aliases = []): iterable
    {
        foreach ($columns as $column) {
            yield TokenSourceDefinition::modelColumn("{$prefix}.{$column}", 'webinars', str("{$prefix} {$column}")->headline()->toString(), "Value stored in {$prefix}.{$column}.", $model, $column, $aliases[$column] ?? []);
        }
    }
}
