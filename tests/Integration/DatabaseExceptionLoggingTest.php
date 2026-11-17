<?php

declare(strict_types=1);

/** Verify redaction of PostgreSQL driver detail and Laravel's interpolated SQL using isolated test storage. */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('redacts a real PostgreSQL query exception through a configured channel', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'signalbox-pg-log-');
    config()->set('logging.channels.sec-postgres', [
        'driver' => 'single',
        'path' => $path,
        'tap' => [\App\Logging\RedactSecrets::class],
    ]);

    try {
        $exception = null;

        try {
            // A savepoint keeps the outer test transaction usable after the expected driver failure.
            DB::transaction(static fn () => DB::select('select cast(? as integer)', ['private@example.test private-hash private-ciphertext']));
        } catch (QueryException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(QueryException::class);
        Log::channel('sec-postgres')->error($exception);
        $output = file_get_contents($path);

        expect($output)->toContain('Database operation failed.', '22P02', 'QueryException', 'PDOException')
            ->not->toContain('private@example.test', 'private-hash', 'private-ciphertext', 'select cast', 'invalid input syntax');
    } finally {
        Log::forgetChannel('sec-postgres');
        unlink($path);
    }
})->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires isolated PostgreSQL.');
