<?php

declare(strict_types=1);

/** Share one decoded byte allowance across all response hops of a single observation. */

namespace App\Services\Monitoring\Checks;

use InvalidArgumentException;

/** A rejected write permanently marks the observation incomplete. */
final class ResponseByteBudget
{
    /** @var int Decoded bytes accepted across response streams. */
    private int $used = 0;

    /** @var bool Whether any incoming write exceeded the cumulative allowance. */
    public bool $exceeded = false;

    /**
     * @param int $maximum Positive cumulative decoded-byte cap.
     * @throws InvalidArgumentException When the cap is not positive.
     */
    public function __construct(private readonly int $maximum)
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('Response byte budgets must be positive.');
        }
    }

    /**
     * @param int $bytes Size in bytes of the next decoded write bucket.
     * @return bool Whether the entire bucket fits; rejected data must never be evaluated as a complete response.
     */
    public function accept(int $bytes): bool
    {
        if ($this->exceeded || $bytes > $this->maximum - $this->used) {
            $this->exceeded = true;

            return false;
        }

        $this->used += $bytes;

        return true;
    }
}
