<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use Illuminate\Support\Facades\DB;

class DeleteWebinarSeriesAction
{
    public function handle(WebinarSeries $series): void
    {
        DB::transaction(function () use ($series): void {
            $target = WebinarSeries::query()
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $chainIds = WebinarSeriesMessageChainBinding::query()
                ->where('webinar_series_id', $target->getKey())
                ->pluck('message_chain_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            WebinarSeriesMessageChainBinding::query()
                ->where('webinar_series_id', $target->getKey())
                ->delete();

            $target->delete();

            foreach ($chainIds as $chainId) {
                $stillBound = WebinarSeriesMessageChainBinding::query()
                    ->where('message_chain_id', $chainId)
                    ->exists()
                    || WebinarScheduleProfileChainBinding::query()
                        ->where('message_chain_id', $chainId)
                        ->exists();
                $hasRuntimeReferences = DB::table('message_chain_enrollments')
                    ->join(
                        'message_chain_versions',
                        'message_chain_versions.id',
                        '=',
                        'message_chain_enrollments.message_chain_version_id',
                    )
                    ->where(
                        'message_chain_versions.message_chain_id',
                        $chainId,
                    )
                    ->exists()
                    || DB::table('scheduled_messages')
                        ->join(
                            'message_chain_step_variants',
                            'message_chain_step_variants.id',
                            '=',
                            'scheduled_messages.message_chain_step_variant_id',
                        )
                        ->join(
                            'message_chain_steps',
                            'message_chain_steps.id',
                            '=',
                            'message_chain_step_variants.message_chain_step_id',
                        )
                        ->join(
                            'message_chain_versions',
                            'message_chain_versions.id',
                            '=',
                            'message_chain_steps.message_chain_version_id',
                        )
                        ->where(
                            'message_chain_versions.message_chain_id',
                            $chainId,
                        )
                        ->exists();

                if (! $stillBound && ! $hasRuntimeReferences) {
                    MessageChain::query()->whereKey($chainId)->delete();
                }
            }

            MessageTemplate::query()
                ->where(
                    'key',
                    'like',
                    'webinar.series.'.$target->getKey().'.%',
                )
                ->whereDoesntHave(
                    'versions.chainStepVariants',
                )
                ->whereDoesntHave(
                    'versions.scheduledMessages',
                )
                ->whereDoesntHave(
                    'versions.scheduledMessageComponents',
                )
                ->eachById(
                    fn (MessageTemplate $template): bool =>
                        (bool) $template->delete(),
                );
        }, 3);
    }
}