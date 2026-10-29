<?php

declare(strict_types=1);

/** Define the admitted single-execution runner boundary retained for legacy consumers. */

namespace App\Contracts\Monitoring;

use App\Models\CheckExecution;

/** A consumer runs only after atomic admission; result persistence must use the acquisition fence. */
interface CheckWorkConsumer
{
    /**
     * @param CheckExecution $execution Acquired tenant-owned slot with deadline and fencing token.
     * @throws \Throwable On infrastructure failure; target failures are ordinary check results.
     */
    public function consume(CheckExecution $execution): void;
}
