<?php

declare(strict_types=1);

/** Separate pure HTTP-family expectations from protected concurrent response acquisition. */

namespace App\Contracts\Monitoring;

use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HttpObservation;

/** Implementations neither perform I/O nor persist results. */
interface HttpResponseStrategy
{
    /**
     * @param CheckInput $input Validated configuration and trusted correlation identity.
     * @param HttpObservation $observation Complete bounded HTTP observation.
     * @return CheckOutcome Safe status/text evaluation.
     * @throws \InvalidArgumentException When the strategy receives another configuration variant.
     */
    public function evaluate(CheckInput $input, #[\SensitiveParameter] HttpObservation $observation): CheckOutcome;
}
