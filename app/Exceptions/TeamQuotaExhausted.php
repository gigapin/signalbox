<?php

declare(strict_types=1);

/** Signal that a team used every allowed message of one daily quota in the current window. */

namespace App\Exceptions;

use App\Enums\Identity\TeamQuota;
use Carbon\CarbonImmutable;
use DomainException;

/** Raised by TeamDailyQuotas; each action translates it into its own refusal with the reset instant. */
final class TeamQuotaExhausted extends DomainException
{
    /**
     * @param TeamQuota $quota Exhausted counter.
     * @param int $limit Configured maximum per window.
     * @param CarbonImmutable $availableAt UTC instant when the current window ends and the counter restarts.
     */
    public function __construct(
        public readonly TeamQuota $quota,
        public readonly int $limit,
        public readonly CarbonImmutable $availableAt,
    ) {
        parent::__construct('The team reached its daily limit of '.$limit.' for '.$quota->value.'.');
    }
}
