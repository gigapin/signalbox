<?php

declare(strict_types=1);

/** Freeze the exact tenant/page/hostname/generation authorized before DNS I/O. */

namespace App\Data\StatusPages;

/** A proof for an older generation can never authorize a replacement claim. */
final readonly class StatusDomainClaim
{
    /**
     * @param int $teamId Immutable page owner.
     * @param int $pageId Team-local page identity.
     * @param string $hostname Canonical ASCII hostname.
     * @param string $challenge Random DNS TXT proof generation.
     */
    public function __construct(public int $teamId, public int $pageId, public string $hostname, public string $challenge)
    {
    }
}
