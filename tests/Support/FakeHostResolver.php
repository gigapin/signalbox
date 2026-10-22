<?php

declare(strict_types=1);

/** Replace DNS lookups with deterministic answers and record every queried name. */

namespace Tests\Support;

use App\Contracts\Security\HostResolver;

/** Unknown names resolve to no address, matching a failed lookup. */
final class FakeHostResolver implements HostResolver
{
    /** @var list<string> Hostnames queried so far, in order. */
    public array $queries = [];

    /** @param array<string, list<string>> $answers Hostname mapped to the addresses returned for it. */
    public function __construct(private array $answers = [])
    {
    }

    /**
     * @param string $host Normalized hostname.
     * @return list<string> Configured answers, or none.
     */
    public function resolve(string $host): array
    {
        $this->queries[] = $host;

        return $this->answers[$host] ?? [];
    }
}
