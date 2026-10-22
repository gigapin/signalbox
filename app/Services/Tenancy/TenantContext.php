<?php

/** Isolate tenant identity within a request or an explicitly bounded unit of work. */

namespace App\Services\Tenancy;

use App\Exceptions\MissingTenantContext;
use Closure;
use InvalidArgumentException;

/** Carry identity only; callers must independently establish authority to enter a team. */
final class TenantContext
{
    /** @var int|null Active tenant, absent outside a bounded operation. */
    private ?int $teamId = null;

    /**
     * @return int Active tenant identity; absence never means unrestricted access.
     * @throws MissingTenantContext When no unit of work has established a tenant.
     */
    public function requireTeamId(): int
    {
        return $this->teamId ?? throw new MissingTenantContext('An explicit tenant context is required.');
    }

    /**
     * Run an authorized operation and restore the enclosing identity even after failure.
     *
     * @template TResult
     * @param int $teamId Positive, previously authorized tenant identifier.
     * @param Closure(): TResult $operation Work that must not escape its tenant boundary.
     * @return TResult Result produced by the operation.
     * @throws InvalidArgumentException When the identifier is not positive.
     */
    public function run(int $teamId, Closure $operation): mixed
    {
        if ($teamId < 1) {
            throw new InvalidArgumentException('Tenant identifiers must be positive.');
        }

        $previous = $this->teamId;
        $this->teamId = $teamId;

        try {
            return $operation();
        } finally {
            $this->teamId = $previous;
        }
    }
}
