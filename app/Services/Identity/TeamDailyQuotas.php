<?php

declare(strict_types=1);

/** Count team-wide uses of a quota in a 24-hour window stored in PostgreSQL. */

namespace App\Services\Identity;

use App\Enums\Identity\TeamQuota;
use App\Exceptions\TeamQuotaExhausted;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The window starts at the first use after the previous window ended and lasts 24 hours; the counter then restarts
 * from zero. A database row, rather than a cache entry, keeps the count across cache flushes and follows the
 * application clock, so tests can cross the window boundary. Uses are never refunded: a refused or failed delivery
 * still counts, which keeps the bound simple and errs on the side of fewer messages.
 */
final readonly class TeamDailyQuotas
{
    /** @var int Window length in seconds. */
    public const int WINDOW_SECONDS = 86400;

    /** @param Repository $config Source of each quota's configured limit. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * Record one use or refuse it. Call inside the transaction that commits the guarded change, so a rolled back
     * change also rolls back its use; callers already hold the Team row lock, and the counter row lock below keeps
     * the check correct on its own.
     *
     * @param int $teamId Team whose counter is charged.
     * @param TeamQuota $quota Counter to charge.
     * @param CarbonImmutable $now Application instant of the attempt.
     * @throws TeamQuotaExhausted When the window already holds the configured number of uses.
     * @throws LogicException When called outside a database transaction.
     */
    public function consume(int $teamId, TeamQuota $quota, CarbonImmutable $now): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Team quotas must be charged inside the transaction of the guarded change.');
        }

        $limit = (int) $this->config->get($quota->configKey());

        DB::table('team_daily_quotas')->insertOrIgnore([
            'team_id' => $teamId,
            'quota' => $quota->value,
            'window_started_at' => $now,
            'used' => 0,
        ]);

        $row = DB::table('team_daily_quotas')
            ->where('team_id', $teamId)
            ->where('quota', $quota->value)
            ->lockForUpdate()
            ->first(['window_started_at', 'used']);

        if ($row === null) {
            throw new LogicException('The quota row must exist after its insertion.');
        }

        $windowStart = CarbonImmutable::parse((string) $row->window_started_at);
        $used = (int) $row->used;

        if ($now->greaterThanOrEqualTo($windowStart->addSeconds(self::WINDOW_SECONDS))) {
            $windowStart = $now;
            $used = 0;
        }

        if ($used >= $limit) {
            throw new TeamQuotaExhausted($quota, $limit, $windowStart->addSeconds(self::WINDOW_SECONDS)->utc());
        }

        DB::table('team_daily_quotas')
            ->where('team_id', $teamId)
            ->where('quota', $quota->value)
            ->update([
                'window_started_at' => $windowStart,
                'used' => $used + 1,
            ]);
    }
}
