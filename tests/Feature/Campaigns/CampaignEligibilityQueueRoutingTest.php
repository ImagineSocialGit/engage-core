<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Jobs\ReconcileAutomaticCampaignEligibilityJob;
use App\Modules\Campaigns\Jobs\ReconcileContactCampaignEligibilityJob;
use App\Support\Queues\QueueContract;
use Tests\TestCase;

class CampaignEligibilityQueueRoutingTest extends TestCase
{
    public function test_both_eligibility_jobs_use_a_registered_queue_consumed_by_horizon(): void
    {
        $jobs = [
            new ReconcileAutomaticCampaignEligibilityJob,
            new ReconcileContactCampaignEligibilityJob(
                contactId: 1,
                criterionKeys: ['status'],
                occurredAt: '2026-09-18T00:00:00+00:00',
            ),
        ];

        $productionQueues = config(
            'horizon.environments.production.supervisor-1.queue',
            [],
        );

        $this->assertIsArray($productionQueues);

        foreach ($jobs as $job) {
            $this->assertSame('default', $job->queue);
            $this->assertContains($job->queue, QueueContract::QUEUES);
            $this->assertContains($job->queue, $productionQueues);
        }
    }
}