<?php

namespace App\Modules\Webinars\TokenContracts;

use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;

class WebinarTokenContextProvider implements TokenContextProvider
{
    private const CONTACT = ['contact.first_name', 'contact.last_name', 'contact.name', 'contact.email', 'contact.phone'];
    private const WEBINAR = ['webinar.id', 'webinar.title', 'webinar.slug', 'webinar.platform', 'webinar.registration_url', 'webinar.starts_at', 'webinar.ends_at', 'webinar.timezone', 'webinar.description', 'webinar_start_date', 'webinar_start_time', 'webinar_start_datetime'];
    private const SERIES = ['webinar_series.id', 'webinar_series.title', 'webinar_series.slug', 'webinar_series.status'];

    public function contexts(): iterable
    {
        yield new TokenContextDefinition('registration_created', 'webinars', 'Registration confirmations and reminders.', [...self::CONTACT, ...self::WEBINAR, ...self::SERIES, 'webinar_registration.id', 'webinar_registration.status', 'webinar_registration.registered_at', 'webinar_join_url', 'cancel_registration_url'], ['email', 'sms'], ['transactional'], ['webinar'], ['webinar_registrations']);
        yield new TokenContextDefinition('webinar_added', 'webinars', 'Waitlist availability notifications.', [...self::CONTACT, ...self::WEBINAR, ...self::SERIES, 'webinar_waitlist_signup.id', 'webinar_waitlist_signup.source_page', 'webinar_waitlist_registration_url'], ['email', 'sms'], ['marketing'], ['webinar_waitlist'], ['webinar_waitlists']);
        yield new TokenContextDefinition('webinar_ended', 'webinars', 'Post-webinar follow-up.', [...self::CONTACT, ...self::WEBINAR, ...self::SERIES, 'webinar_registration.id', 'webinar_registration.attended_at', 'webinar_playback_url', 'webinar_end_date', 'webinar_end_time', 'webinar_end_datetime'], ['email', 'sms'], ['transactional'], ['webinar'], ['webinar_registrations']);
    }
}
