<?php

declare(strict_types=1);

/** Validate HTTP redirect hops with the same guard applied to the original destination. */

namespace App\Services\Security;

use App\Data\Security\AuthorizedDestination;
use App\Data\Security\RedirectStep;
use App\Enums\Security\OutboundRejection;
use App\Exceptions\OutboundDestinationRejected;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Method and body rewriting for 301/302/303 versus 307/308 belongs to the M3 transport;
 * this policy owns only target authorization, hop limits, downgrade refusal and credential forwarding.
 */
final readonly class RedirectPolicy
{
    /** @var int Maximum number of redirects one observation may follow. */
    private int $maxRedirects;

    /**
     * @param OutboundDestinationGuard $guard Shared SSRF boundary applied again to every hop.
     * @param Repository $config Application configuration containing outbound-security.max_redirects.
     * @throws LogicException When the configured limit is not a non-negative integer.
     */
    public function __construct(private OutboundDestinationGuard $guard, Repository $config)
    {
        $maximum = $config->get('outbound-security.max_redirects');

        if (!is_int($maximum) || $maximum < 0) {
            throw new LogicException('The outbound redirect limit must be a non-negative integer.');
        }

        $this->maxRedirects = $maximum;
    }

    /**
     * @param AuthorizedDestination $current Destination that returned the redirect response.
     * @param string $location Raw Location header value, absolute or relative.
     * @param int $followed Number of redirects already followed before this one.
     * @return RedirectStep Authorized next hop with its credential forwarding rule.
     * @throws OutboundDestinationRejected When the limit, scheme downgrade or the new target is refused.
     */
    public function next(AuthorizedDestination $current, #[\SensitiveParameter] string $location, int $followed): RedirectStep
    {
        if ($followed >= $this->maxRedirects) {
            throw new OutboundDestinationRejected(OutboundRejection::RedirectLimit);
        }

        if ($current->url === null) {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        $next = $this->guard->authorizeUrl($this->resolve($current->url, $location));

        if ($current->scheme === 'https' && $next->scheme === 'http') {
            throw new OutboundDestinationRejected(OutboundRejection::RedirectDowngrade);
        }

        return new RedirectStep($next, $current->origin() !== $next->origin());
    }

    /**
     * Resolve a reference against the current URL. Dot segments are left to the server; the result is
     * normalized and authorized afterwards, so path accuracy is not a security property here.
     *
     * @param string $base Normalized absolute URL of the current request.
     * @param string $location Raw Location value.
     * @return string Absolute URL without fragment.
     * @throws OutboundDestinationRejected When the reference cannot be resolved.
     */
    private function resolve(#[\SensitiveParameter] string $base, #[\SensitiveParameter] string $location): string
    {
        $location = trim(explode('#', $location, 2)[0]);

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $location === '') {
            throw new OutboundDestinationRejected(OutboundRejection::InvalidDestination);
        }

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '/';

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        if (str_starts_with($location, '?')) {
            return $origin.$path.$location;
        }

        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$location;
    }
}
