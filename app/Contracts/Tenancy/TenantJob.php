<?php

/** Require tenant jobs to serialize identifiers instead of tenant Eloquent models. */

namespace App\Contracts\Tenancy;

/** Provide the explicit execution identity; resource ownership is checked after entering context. */
interface TenantJob
{
    /** @return int Positive team identity recorded by trusted dispatch orchestration. */
    public function tenantId(): int;
}
