<?php

/** Exercise real queue serialization, tenant middleware and resource reloading with scalar inputs. */

namespace Tests\Fixtures;

use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Store only synthetic observations in the in-process test cache, never product side effects. */
final class TenantProbeJob implements ShouldQueue, TenantJob
{
    /** @var int $tries Synthetic failures must reach the worker's failed path immediately. */
    public int $tries = 1;

    /**
     * @param int $teamId Trusted dispatch identity.
     * @param int $recordId Resource reloaded only after middleware establishes context.
     * @param string $observation Unique test cache key.
     * @param bool $fail Whether to simulate failure after loading a valid resource.
     */
    public function __construct(
        public readonly int $teamId,
        public readonly int $recordId,
        public readonly string $observation,
        public readonly bool $fail = false,
    ) {
    }

    /** @return int Explicit serialized identity, independent of authentication and current preference. */
    public function tenantId(): int
    {
        return $this->teamId;
    }

    /** @return list<UseTenantContext> Middleware establishing context before handle loads private data. */
    public function middleware(): array
    {
        return [new UseTenantContext()];
    }

    /** @param TenantContext $context Execution identity supplied by the scoped container binding. */
    public function handle(TenantContext $context): void
    {
        $record = TenantRecord::query()->findOrFail($this->recordId);
        Cache::put($this->observation, [$context->requireTeamId(), $record->title]);

        if ($this->fail) {
            throw new RuntimeException('Synthetic tenant worker failure.');
        }
    }

    /** @param Throwable $exception Worker failure observed after middleware has unwound its context. */
    public function failed(Throwable $exception): void
    {
        Cache::put($this->observation.':failed', $exception::class);
    }
}
