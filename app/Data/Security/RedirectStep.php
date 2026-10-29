<?php

declare(strict_types=1);

/** Describe one validated redirect hop and the credentials it may carry. */

namespace App\Data\Security;

/** Configured monitor headers are forwarded only while the redirect stays on the same origin. */
final readonly class RedirectStep
{
    /**
     * @param AuthorizedDestination $destination Newly authorized and pinned target of the hop.
     * @param bool $crossOrigin Whether scheme, host or port differ from the previous request.
     */
    public function __construct(public AuthorizedDestination $destination, public bool $crossOrigin)
    {
    }

    /**
     * @param array<string, string> $configured Monitor headers sent on the original request, including credentials.
     * @return array<string, string> Headers to send on this hop: unchanged on the same origin, none across origins.
     */
    public function headers(#[\SensitiveParameter] array $configured): array
    {
        return $this->crossOrigin ? [] : $configured;
    }
}
