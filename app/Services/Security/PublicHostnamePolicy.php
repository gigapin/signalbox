<?php

declare(strict_types=1);

/** Apply the shared network-free naming boundary to customer domains and outbound DNS questions. */

namespace App\Services\Security;

use App\Enums\Security\OutboundRejection;
use App\Exceptions\OutboundDestinationRejected;

/** This suffix filter cannot identify private split-horizon zones beneath public domain names. */
final class PublicHostnamePolicy
{
    /** @var list<string> Reserved or internal suffixes, matched as complete final labels. */
    private const BLOCKED_SUFFIXES = ['localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion'];

    /**
     * @param string $name Canonical lowercase ASCII hostname without a terminal dot; never an IP literal.
     * @return bool Whether the name is qualified and outside reserved/internal suffixes.
     */
    public function allows(string $name): bool
    {
        $suffix = substr($name, (int) strrpos($name, '.') + 1);

        return str_contains($name, '.') && !in_array($suffix, self::BLOCKED_SUFFIXES, true);
    }

    /**
     * @param string $name Canonical DNS question or followed alias, allowing underscore labels.
     * @throws OutboundDestinationRejected Before querying or interpreting an internal name.
     */
    public function assertAllowed(string $name): void
    {
        if (!$this->allows($name)) {
            throw new OutboundDestinationRejected(OutboundRejection::LocalName);
        }
    }
}
