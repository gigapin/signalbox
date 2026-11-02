<?php

/** Schedule queue observability, the explicitly gated recoverable monitoring pipeline and nightly metric maintenance. */

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

// Recover committed notifications after publication gaps, expired attempts and provider backoff.
Schedule::command('notifications:dispatch')->everyTenSeconds()->onOneServer()->withoutOverlapping(1);

// Recover verified public updates and double opt-in messages through the existing notifications workers.
Schedule::command('status-pages:dispatch-emails')->everyTenSeconds()->onOneServer()->withoutOverlapping(1);

// Long-running by design: in the background it cannot delay the second-level commands above in the same scheduler.
Schedule::command('metrics:nightly')->dailyAt('02:00')->timezone('UTC')->onOneServer()->withoutOverlapping(720)->runInBackground();
