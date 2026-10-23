<?php

declare(strict_types=1);

/** Commit a synthetic Free team outside the RefreshDatabase transaction so other processes can see it. */

namespace Tests\Support;

use App\Actions\Monitoring\ManageMonitors;
use App\Models\User;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Rows are written through a second connection copied from the guarded test connection, so they are committed
 * and visible to a separate HTTP server process. Callers must invoke cleanup() in a finally block: the test
 * transaction rollback does not remove them.
 */
final readonly class CommittedTeamFixture
{
    /** @var string CONNECTION Connection name registered for committed writes. */
    public const string CONNECTION = 'committed_probe';

    /** @var string PASSWORD Synthetic fixture password used to sign in through the real login form. */
    public const string PASSWORD = 'synthetic-quota-password';

    /**
     * @param ConnectionInterface $connection Committing connection, separate from the test transaction.
     * @param User $owner Verified owner of the team.
     * @param int $teamId Free team whose trial has ended.
     */
    private function __construct(
        public ConnectionInterface $connection,
        public User $owner,
        public int $teamId,
    ) {
    }

    /**
     * @param int $monitors Monitors created through the production action before the test starts.
     * @return self Committed owner, Free team past its trial and membership, plus the seeded monitors.
     */
    public static function freeTeam(int $monitors): self
    {
        config(['database.connections.'.self::CONNECTION => config('database.connections.pgsql')]);
        $connection = DB::connection(self::CONNECTION);
        $suffix = (string) Str::uuid();

        $ownerId = $connection->table('users')->insertGetId([
            'name' => 'Quota owner',
            'email' => 'quota-'.$suffix.'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);
        $teamId = $connection->table('teams')->insertGetId([
            'name' => 'Quota contention',
            'slug' => 'quota-'.$suffix,
            'owner_id' => $ownerId,
            'plan' => 'free',
            'timezone' => 'UTC',
            'trial_started_at' => now()->subDays(30),
            'trial_ends_at' => now()->subDays(16),
        ]);
        $connection->table('team_user')->insert(['team_id' => $teamId, 'user_id' => $ownerId, 'role' => 'owner']);

        $fixture = new self($connection, User::query()->findOrFail($ownerId), $teamId);

        $fixture->committed(function () use ($fixture, $monitors): void {
            for ($created = 0; $created < $monitors; $created++) {
                app(ManageMonitors::class)->create($fixture->owner, $fixture->teamId, MonitorInputFixture::http());
            }
        });

        return $fixture;
    }

    /**
     * @template TResult
     * @param Closure(): TResult $operation Work whose default-connection queries must commit outside the test transaction.
     * @return TResult Operation result; the default connection is always restored.
     */
    public function committed(Closure $operation): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::CONNECTION);

        try {
            return $operation();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /** @return int Committed monitors of the team, paused and suspended ones included. */
    public function monitorCount(): int
    {
        return $this->connection->table('monitors')->where('team_id', $this->teamId)->count();
    }

    /** Delete the committed team (monitors and memberships cascade) and its owner, then drop the connection. */
    public function cleanup(): void
    {
        $this->connection->table('teams')->where('id', $this->teamId)->delete();
        $this->connection->table('users')->where('id', $this->owner->id)->delete();
        DB::purge(self::CONNECTION);
    }
}
