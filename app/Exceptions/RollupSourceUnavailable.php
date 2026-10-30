<?php

declare(strict_types=1);

/** Signal that a requested rebuild would need raw results that are no longer retained. */

namespace App\Exceptions;

use RuntimeException;

/** Refusing keeps a rebuild from replacing good rollups with time wrongly calculated as unknown. */
final class RollupSourceUnavailable extends RuntimeException
{
}
