<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarSeriesRemovalPlan;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Support\Facades\DB;

final class RemoveWebinarSeriesAction
{
    public const RESULT_DELETED = 'deleted';
    public const RESULT_ARCHIVED = 'archived';

    public function __construct(
        private readonly DeleteWebinarSeriesAction $deleteWebinarSeries,
    ) {}

    public function plan(WebinarSeries $series): WebinarSeriesRemovalPlan
    {
        return new WebinarSeriesRemovalPlan(
            sessionCount: Webinar::query()
                ->where('webinar_series_id', $series->getKey())
                ->count(),
            waitlistSignupCount: WebinarWaitlistSignup::query()
                ->where('webinar_series_id', $series->getKey())
                ->count(),
            suppressionCount: WebinarOccurrenceSuppression::query()
                ->where('webinar_series_id', $series->getKey())
                ->count(),
        );
    }

    public function handle(WebinarSeries $series): string
    {
        return DB::transaction(function () use ($series): string {
            $locked = WebinarSeries::query()
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $plan = $this->plan($locked);

            if ($plan->canDelete()) {
                $this->deleteWebinarSeries->handle($locked);

                return self::RESULT_DELETED;
            }

            if ((string) $locked->status !== 'inactive') {
                $locked->forceFill([
                    'status' => 'inactive',
                ])->save();
            }

            return self::RESULT_ARCHIVED;
        }, 3);
    }
}