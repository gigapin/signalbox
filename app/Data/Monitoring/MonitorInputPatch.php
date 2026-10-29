<?php

declare(strict_types=1);

/** Retain only explicitly submitted, normalized fields for later validation against locked state. */

namespace App\Data\Monitoring;

/** A patch is sensitive input, not a mass-assignment payload or an authorization grant. */
final readonly class MonitorInputPatch
{
    /** @param array<string, mixed> $values Explicit normalized keys; omitted old secrets are never copied here. */
    public function __construct(#[\SensitiveParameter] public array $values)
    {
    }
}
