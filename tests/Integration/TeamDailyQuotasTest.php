<?php

declare(strict_types=1);

/** Verify the rolling 24-hour team quota counters on PostgreSQL, including two competing sessions. */

use App\Enums\Identity\TeamQuota;
use App\Exceptions\TeamQuotaExhausted;
use App\Models\Team;
use App\Services\Identity\TeamDailyQuotas;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    config([
        'notifications.verification_sends_per_team_per_day' => 2,
        'teams.invitations_per_team_per_day' => 1,
    ]);
});

/**
 * Charge one use inside a transaction, as the production actions do.
 *
 * @param int $teamId Team to charge.
 * @param TeamQuota $quota Counter to charge.
 * @param CarbonImmutable $now Instant of the attempt.
 * @throws TeamQuotaExhausted When the counter is full.
 */
function quotaConsume(int $teamId, TeamQuota $quota, CarbonImmutable $now): void
{
    DB::transaction(fn () => app(TeamDailyQuotas::class)->consume($teamId, $quota, $now));
}

/**
 * @param int $teamId Team whose counter is read.
 * @param TeamQuota $quota Counter to read.
 * @return int Uses stored for the current window, or 0 without a row.
 */
function quotaUsed(int $teamId, TeamQuota $quota): int
{
    return (int) DB::table('team_daily_quotas')
        ->where('team_id', $teamId)
        ->where('quota', $quota->value)
        ->value('used');
}

it('refuses uses beyond the limit until exactly 24 hours after the window started', function (): void {
    $team = Team::factory()->create();
    $start = CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC');

    quotaConsume($team->id, TeamQuota::ChannelVerification, $start);
    quotaConsume($team->id, TeamQuota::ChannelVerification, $start->addHours(23));

    try {
        quotaConsume($team->id, TeamQuota::ChannelVerification, $start->addDay()->subSecond());
        $this->fail('The third use inside the window must be refused.');
    } catch (TeamQuotaExhausted $exhausted) {
        expect($exhausted->limit)->toBe(2)
            ->and($exhausted->quota)->toBe(TeamQuota::ChannelVerification)
            ->and($exhausted->availableAt->toIso8601String())->toBe('2026-11-14T09:00:00+00:00');
    }

    expect(quotaUsed($team->id, TeamQuota::ChannelVerification))->toBe(2);

    quotaConsume($team->id, TeamQuota::ChannelVerification, $start->addDay());

    $window = DB::table('team_daily_quotas')
        ->where('team_id', $team->id)
        ->value('window_started_at');

    expect(quotaUsed($team->id, TeamQuota::ChannelVerification))->toBe(1)
        ->and(CarbonImmutable::parse((string) $window)->utc()->toIso8601String())->toBe('2026-11-14T09:00:00+00:00');
});

it('keeps counters separate per team and per quota', function (): void {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $now = CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC');

    quotaConsume($first->id, TeamQuota::Invitation, $now);

    expect(fn () => quotaConsume($first->id, TeamQuota::Invitation, $now))->toThrow(TeamQuotaExhausted::class);

    quotaConsume($second->id, TeamQuota::Invitation, $now);
    quotaConsume($first->id, TeamQuota::ChannelVerification, $now);

    expect(quotaUsed($second->id, TeamQuota::Invitation))->toBe(1)
        ->and(quotaUsed($first->id, TeamQuota::ChannelVerification))->toBe(1);
});

it('rolls back a use together with the change that failed after charging it', function (): void {
    $team = Team::factory()->create();
    $now = CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC');

    try {
        DB::transaction(function () use ($team, $now): void {
            app(TeamDailyQuotas::class)->consume($team->id, TeamQuota::Invitation, $now);

            throw new RuntimeException('The guarded change failed.');
        });
    } catch (RuntimeException) {
    }

    expect(quotaUsed($team->id, TeamQuota::Invitation))->toBe(0);
});

it('serializes two sessions charging the same counter and refuses use outside a transaction', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $contender = 'quota_contender';
    $now = CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC');
    config([
        'database.connections.'.$contender => config('database.connections.pgsql'),
        'teams.invitations_per_team_per_day' => 5,
    ]);

    try {
        $fixture->committed(function () use ($fixture, $now): void {
            expect(fn () => app(TeamDailyQuotas::class)->consume($fixture->teamId, TeamQuota::Invitation, $now))
                ->toThrow(LogicException::class);

            DB::beginTransaction();
            app(TeamDailyQuotas::class)->consume($fixture->teamId, TeamQuota::Invitation, $now);
        });

        DB::connection($contender)->statement("SET lock_timeout = '100ms'");
        DB::setDefaultConnection($contender);

        try {
            DB::transaction(fn () => app(TeamDailyQuotas::class)->consume($fixture->teamId, TeamQuota::Invitation, $now));
            $this->fail('A second session must wait for the first session to commit its use.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        $fixture->committed(fn () => DB::commit());

        DB::setDefaultConnection($contender);

        try {
            DB::transaction(fn () => app(TeamDailyQuotas::class)->consume($fixture->teamId, TeamQuota::Invitation, $now));
        } finally {
            DB::setDefaultConnection('pgsql');
        }

        $used = $fixture->connection->table('team_daily_quotas')
            ->where('team_id', $fixture->teamId)
            ->value('used');

        expect((int) $used)->toBe(2);
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::purge($contender);

        // A failed assertion can leave the first session's transaction open; cleanup must commit its deletes.
        while ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        $fixture->cleanup();
    }
});
