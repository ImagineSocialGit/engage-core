<?php

namespace App\Support\TestingTools;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

class TestingToolRuntime
{
    private static int $activeDepth = 0;

    public function __construct(
        private readonly TestingToolGuard $guard,
    ) {}

    public function active(): bool
    {
        return self::$activeDepth > 0;
    }

    /**
     * Execute one testing-tool operation under a scoped fake clock while
     * suppressing any queued work the real runtime attempts to dispatch.
     *
     * The original Carbon test clock and Queue facade root are always restored,
     * even when the runtime throws.
     */
    public function runAt(CarbonInterface|string $fakeNow, Closure $callback): mixed
    {
        $this->guard->assertAvailable();

        $resolvedNow = $fakeNow instanceof CarbonInterface
            ? Carbon::instance($fakeNow)
            : Carbon::parse($fakeNow);

        $previousTestNow = Carbon::getTestNow();
        $previousQueue = Queue::getFacadeRoot();

        self::$activeDepth++;
        Queue::fake();
        Carbon::setTestNow($resolvedNow);

        try {
            return $callback($resolvedNow);
        } finally {
            Carbon::setTestNow($previousTestNow);

            if ($previousQueue !== null) {
                Queue::swap($previousQueue);
            } else {
                Queue::clearResolvedInstance('queue');
            }

            self::$activeDepth = max(0, self::$activeDepth - 1);
        }
    }
}