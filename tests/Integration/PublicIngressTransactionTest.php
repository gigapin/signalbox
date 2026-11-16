<?php

declare(strict_types=1);

/** Verify bounded PostgreSQL lock waits preserve caller settings, savepoints and unrelated outer work. */

use App\Exceptions\PublicIngressBusy;
use App\Models\Team;
use App\Services\Database\PublicIngressTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL lock and savepoint behavior.');
    }
});

/** @return int Effective PostgreSQL lock timeout in milliseconds, independent of its display unit. */
function publicIngressTimeoutMs(): int
{
    return (int) DB::scalar("SELECT setting FROM pg_settings WHERE name = 'lock_timeout'");
}

it('caps lock waits and restores a successful nested callers exact timeout', function (string $prior, int $expected): void {
    DB::select("SELECT set_config('lock_timeout', ?, true)", [$prior]);
    $before = publicIngressTimeoutMs();
    $level = DB::transactionLevel();
    $result = DB::transaction(function () use ($expected): string {
        return app(PublicIngressTransaction::class)->run(function () use ($expected): string {
            expect(publicIngressTimeoutMs())->toBe($expected);

            return 'committed result';
        });
    });

    expect($result)->toBe('committed result')
        ->and(publicIngressTimeoutMs())->toBe($before)
        ->and(DB::transactionLevel())->toBe($level);
})->with([
    'unbounded caller' => ['0', 250],
    'two second caller' => ['2s', 250],
    'stricter caller' => ['25ms', 25],
    'same bound' => ['250ms', 250],
]);

it('restores nested settings after domain and SQL failures without losing outer writes', function (bool $sqlFailure): void {
    $team = Team::factory()->create(['name' => 'Outer transaction']);
    DB::select("SELECT set_config('lock_timeout', '2s', true)");
    $level = DB::transactionLevel();

    try {
        app(PublicIngressTransaction::class)->run(function () use ($team, $sqlFailure): void {
            DB::table('teams')->where('id', $team->id)->update(['name' => 'Rolled-back inner write']);

            if ($sqlFailure) {
                DB::statement('SELECT missing_public_ingress_column FROM teams');
            }

            throw new RuntimeException('Synthetic domain failure.');
        });
        $this->fail('The failed operation must propagate its real error.');
    } catch (QueryException $error) {
        expect($sqlFailure)->toBeTrue()->and($error->errorInfo[0])->toBe('42703');
    } catch (RuntimeException $error) {
        expect($sqlFailure)->toBeFalse()->and($error->getMessage())->toBe('Synthetic domain failure.');
    }

    expect(publicIngressTimeoutMs())->toBe(2000)
        ->and(DB::transactionLevel())->toBe($level)
        ->and($team->fresh()?->name)->toBe('Outer transaction');
    DB::table('teams')->where('id', $team->id)->update(['name' => 'Outer work remains usable']);
    expect($team->fresh()?->name)->toBe('Outer work remains usable');
})->with([false, true]);

it('restores each nested ingress level before returning to its caller', function (): void {
    DB::select("SELECT set_config('lock_timeout', '2s', true)");
    app(PublicIngressTransaction::class)->run(function (): void {
        expect(publicIngressTimeoutMs())->toBe(250);
        app(PublicIngressTransaction::class)->run(function (): void {
            expect(publicIngressTimeoutMs())->toBe(250);
        });
        expect(publicIngressTimeoutMs())->toBe(250);
    });
    expect(publicIngressTimeoutMs())->toBe(2000);
});

it('translates real lock contention only after rolling back the root or nested operation', function (bool $nested): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $previous = DB::getDefaultConnection();
    config(['database.connections.ingress_timeout_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('ingress_timeout_probe');
    $probe->statement("SET lock_timeout = '2s'");

    try {
        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
        DB::setDefaultConnection('ingress_timeout_probe');
        $attempt = function () use ($fixture): void {
            $level = DB::transactionLevel();

            try {
                app(PublicIngressTransaction::class)->run(
                    fn () => DB::table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first(),
                );
                $this->fail('A competing lock must not wait indefinitely.');
            } catch (PublicIngressBusy $error) {
                expect($error->getMessage())->toBe('The service is busy. Please try again shortly.')
                    ->and($error->getPrevious())->toBeNull();
            }

            expect(publicIngressTimeoutMs())->toBe(2000)
                ->and(DB::transactionLevel())->toBe($level)
                ->and(DB::scalar('SELECT 1'))->toBe(1);
        };

        if ($nested) {
            $probe->transaction($attempt);
        } else {
            $attempt();
        }

        expect($probe->transactionLevel())->toBe(0);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ingress_timeout_probe');

        while ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }

        $fixture->cleanup();
    }
})->with([false, true]);
