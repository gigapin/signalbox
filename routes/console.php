<?php

/** Schedule queue observability independently of future monitor dispatch commands. */

use Illuminate\Support\Facades\Schedule;

// Sample Horizon metrics every five minutes; overlap locks expire after five minutes.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping(5);
