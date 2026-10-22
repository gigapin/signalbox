<?php

declare(strict_types=1);

/** Return a complete validated snapshot together with the explicit patch that produced it. */

namespace App\Data\Monitoring;

/** Future actions revalidate patch values against locked current state instead of persisting a stale snapshot. */
final readonly class ValidatedMonitorInput
{
    /**
     * @param MonitorInput $data Complete definition for this validation pass.
     * @param MonitorInputPatch $patch Only submitted normalized fields, including explicit null/empty values.
     */
    public function __construct(public MonitorInput $data, public MonitorInputPatch $patch)
    {
    }
}
