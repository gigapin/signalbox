<?php

/** Separate infrastructure-free feature tests from explicitly enabled PostgreSQL integration fixtures. */

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)->in('Feature', 'Integration');

// Skipped integration tests must never initialize database traits during an ordinary offline run.
if (getenv('RUN_DOCKER_TESTS') === '1') {
    pest()->use(RefreshDatabase::class)->in('Integration');
}
