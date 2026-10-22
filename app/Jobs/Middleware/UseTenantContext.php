<?php

/** Bound each tenant queue job to its explicit identity and always clean up afterwards. */

namespace App\Jobs\Middleware;

use App\Contracts\Tenancy\TenantJob;
use App\Services\Tenancy\TenantContext;
use Closure;

/** Jobs load resource IDs inside handle; this middleware cannot protect early model rehydration. */
final class UseTenantContext
{
    /**
     * @template TResult
     * @param TenantJob $job Scalar-only tenant job being executed by the queue pipeline.
     * @param Closure(TenantJob): TResult $next Remaining middleware and job handler.
     * @return TResult Handler result, with the previous context restored on failure as well.
     */
    public function handle(TenantJob $job, Closure $next): mixed
    {
        return app(TenantContext::class)->run($job->tenantId(), fn (): mixed => $next($job));
    }
}
