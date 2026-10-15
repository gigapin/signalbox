<?php

/**
 * Bootstrap Laravel for HTTP feature tests without requiring a running web server.
 */

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/** Provide framework request helpers to application feature tests. */
abstract class TestCase extends BaseTestCase
{
}
