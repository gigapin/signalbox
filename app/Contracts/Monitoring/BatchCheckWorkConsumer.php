<?php

declare(strict_types=1);

/** Admit and execute a bounded scalar regional envelope without exposing private configuration. */

namespace App\Contracts\Monitoring;

/** Batch admission captures immutable inputs before releasing domain locks. */
interface BatchCheckWorkConsumer extends CheckWorkConsumer
{
    /**
     * @param int $teamId Explicit tenant boundary.
     * @param array<int, int> $executions Execution identities mapped to distinct monitor identities.
     * @param string $region Simulated regional queue identity.
     * @param string|null $uuid Owning broker UUID, absent for direct tests.
     * @param int|null $attempt Owning broker attempt, supplied together with UUID.
     */
    public function consumeBatch(int $teamId, array $executions, string $region, ?string $uuid, ?int $attempt): void;
}
