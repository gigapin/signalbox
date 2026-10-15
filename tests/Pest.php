<?php

/**
 * Bind HTTP feature tests to the Laravel test application.
 */

pest()->extend(Tests\TestCase::class)->in('Feature');
