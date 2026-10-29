<?php

declare(strict_types=1);

/** Let other domains react to recorded check results without the monitoring engine depending on them. */

namespace App\Contracts\Monitoring;

use App\Data\Monitoring\RecordedResult;

/** Boundary between result recording and incident detection. */
interface ResultObserver
{
    /**
     * Runs inside the terminal transaction, after the Team → Monitor → execution locks and the result insert;
     * an exception rolls back the result together with every effect of the observer.
     *
     * @param RecordedResult $result Result just written for the active tenant.
     */
    public function recorded(RecordedResult $result): void;
}
