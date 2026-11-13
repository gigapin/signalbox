<?php

declare(strict_types=1);

/** Authenticate the private certificate admission caller independently of public proxy source addresses. */

namespace App\Services\StatusPages;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/** Public reverse proxies must strip this header; only the private ask listener supplies the shared secret. */
final readonly class StatusDomainTlsAccess
{
    /** @var string A separator-free field avoids PHP header-name collisions with dots or underscores. */
    public const string HEADER = 'SignalboxTlsSecret';

    /**
     * @param StatusDomainNames $names Raw socket peer allowlist, never forwarded client identity.
     * @param Repository $config Private deployment secret shared with Caddy's loopback ask listener.
     */
    public function __construct(private StatusDomainNames $names, private Repository $config)
    {
    }

    /**
     * @param Request $request Stateless internal request; duplicate or malformed credential fields are refused.
     * @return bool Whether the peer is allowed and exactly one header matches a configured 32-byte hex secret.
     */
    public function allows(#[\SensitiveParameter] Request $request): bool
    {
        $secret = $this->config->get('status-domains.tls_secret');
        $values = $request->headers->all(self::HEADER);

        return $this->names->allowsTlsPeer($request->server('REMOTE_ADDR'))
            && is_string($secret)
            && preg_match('/^[a-f0-9]{64}$/D', $secret) === 1
            && count($values) === 1
            && is_string($values[0] ?? null)
            && hash_equals($secret, $values[0]);
    }
}
