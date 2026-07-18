<?php

namespace App\Modules\Webinars\Contracts;

use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;

interface WebinarProvider
{
    public function name(): string;

    public function key(): string;

    /**
     * Provider integrations should return ProviderWebinarSnapshot so callers
     * can distinguish authoritative reconciliation data from safe import-only
     * results. Legacy iterables are treated as non-authoritative.
     *
     * @return ProviderWebinarSnapshot|iterable<ProviderWebinarData>
     */
    public function listWebinarsByTitle(string $title): iterable;

    public function registerAttendee(Webinar $webinar, WebinarRegistration $registration): ProviderRegistrationData;

    public function cancelRegistration(WebinarRegistration $registration): void;

    public function parseWebhook(Request $request): ProviderWebhookEvent;

    /**
     * Provider integrations should return ProviderAttendanceSnapshot so
     * callers can separate safe positive evidence from authoritative missed
     * reconciliation. Legacy iterables are treated as non-authoritative.
     *
     * @return ProviderAttendanceSnapshot|iterable<WebinarAttendanceRecord>
     */
    public function listAttendanceRecords(Webinar $webinar): iterable;

    public function getRecording(Webinar $webinar): ?ProviderRecordingData;
}
