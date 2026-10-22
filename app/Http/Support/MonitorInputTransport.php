<?php

declare(strict_types=1);

/** Identify monitor write paths before route binding so middleware preserves meaningful payload bytes. */

namespace App\Http\Support;

use Illuminate\Http\Request;

/** This predicate reserves a transport shape without registering CRUD routes. */
final class MonitorInputTransport
{
    /** @param Request $request Incoming browser request before global input normalization.
     * @return bool Whether typed monitor validation owns whitespace and empty-string normalization.
     */
    public static function matches(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
            && preg_match('#^teams/[0-9]+/monitors(?:/[0-9]+)?$#D', $request->path()) === 1;
    }
}
