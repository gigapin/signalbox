<?php

declare(strict_types=1);

/**
 * Verify rollup locking against a second PostgreSQL session.
 *
 * Kept apart from the fixed-clock rollup tests: creating a partition inside the test transaction would hold a lock on the
 * results parent, and the committed fixture cleanup, which cascades to that parent, would wait for it forever.
 */

use App\Services\Metrics\Rollups\BuildCheckRollups;
use App\Services\Monitoring\Results\ResultPartitions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    // Relative to the oldest partition the schema committed, which moves with the real month.
    $this->oldest = app(ResultPartitions::class)->oldestMonth();
    $this->travelTo($this->oldest->addDays(2)->addHours(12));
});

afterEach(function (): void {
    $this->travelBack();
});

it('serializes writers on the cursor row while leaving the monitor row free for the check engine', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(1);

    try {
        $monitorId = (int) $fixture->connection->table('monitors')->where('team_id', $fixture->teamId)->value('id');

        $fixture->connection->table('monitors')->where('id', $monitorId)->update(['created_at' => $this->oldest->addDay()->addHours(9)->format('Y-m-d\TH:i:s\Z')]);
        $fixture->connection->table('check_rollup_cursors')->insert(['team_id' => $fixture->teamId, 'monitor_id' => $monitorId]);
        $fixture->connection->beginTransaction();
        $fixture->connection->table('check_rollup_cursors')->where('monitor_id', $monitorId)->lockForUpdate()->value('monitor_id');

        DB::statement("SET LOCAL lock_timeout = '100ms'");

        try {
            app(BuildCheckRollups::class)->advance($fixture->teamId, $monitorId);
            $this->fail('A competing run must wait for the cursor held by the other session.');
        } catch (QueryException $exception) {
            // PostgreSQL lock_not_available proves contention at the shared cursor.
            expect($exception->errorInfo[0])->toBe('55P03');
        }

        // The engine's own lock is not blocked by the session holding the cursor. The savepoint releases it again,
        // because the committed fixture cleanup below would otherwise wait for this test's outer transaction.
        DB::beginTransaction();
        expect(DB::table('monitors')->where('id', $monitorId)->lockForUpdate()->value('id'))->toBe($monitorId);
        DB::rollBack();
    } finally {
        $fixture->connection->rollBack();
        $fixture->cleanup();
    }
});
