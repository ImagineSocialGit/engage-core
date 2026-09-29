<?php

namespace App\Modules\Events\Jobs;

use App\Modules\Events\Actions\ReconcileDueEventsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileDueEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'events:due-reconciliation';
    }

    public function handle(ReconcileDueEventsAction $reconcile): void
    {
        $reconcile->handle();
    }
}