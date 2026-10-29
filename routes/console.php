<?php

/** Schedule queue observability and the explicitly gated recoverable monitoring pipeline. */

use Illuminate\Support\Facades\Schedule;

// Sample Horizon metrics every five minutes; overlap locks expire after five minutes.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping(5);

Schedule::useCache('redis');

// Redis coordination reduces redundant scans; PostgreSQL transactions enforce execution uniqueness.
foreach (['monitors:dispatch', 'monitors:publish', 'monitors:recover'] as $command) {
    Schedule::command($command)
        ->everySecond()
        ->onOneServer()
        ->withoutOverlapping(1)
        ->when(static fn (): bool => config('monitor-scheduling.enabled') === true);
}

// Partition maintenance performs no target checks and preserves all retained result months.
Schedule::command('monitors:partitions')->daily()->onOneServer()->withoutOverlapping(5);
