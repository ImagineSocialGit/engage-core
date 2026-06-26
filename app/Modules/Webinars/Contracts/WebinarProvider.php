<?php

namespace App\Modules\Webinars\Contracts;

use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;

interface WebinarProvider
{
    public function name(): string;

    public function key(): string;

    /**
     * @return iterable<\App\Modules\Webinars\Data\ProviderWebinarData>
     */
    public function listWebinarsByTitle(string $title): iterable;

    public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): ProviderRegistrationData;

    public function cancelRegistration(WebinarRegistration $registration): void;

    public function parseWebhook(Request $request): ProviderWebhookEvent;

    /**
     * @return iterable<WebinarAttendanceRecord>
     */
    public function listAttendanceRecords(Webinar $webinar): iterable;

    public function getRecording(Webinar $webinar): ?ProviderRecordingData;
}