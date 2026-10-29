<?php

/** Report private data access attempted outside an explicit tenant unit of work. */

namespace App\Exceptions;

use LogicException;

/** Fail closed rather than silently removing the tenant predicate. */
final class MissingTenantContext extends LogicException
{
}
