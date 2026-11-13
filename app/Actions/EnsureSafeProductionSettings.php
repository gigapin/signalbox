<?php

declare(strict_types=1);

/** Refuse to start outside local development and tests while a setting would silently weaken security. */

namespace App\Actions;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Each rule covers a setting whose unsafe value produces no error at runtime: cookies without `Secure`, debug pages,
 * reset and invitation tokens written to the log, per-IP limits that become global or bypassable, a reopened SSRF
 * path to private networks, or a WebSocket server that accepts loopback origins. Outside `local` and `testing` every
 * violation is collected and reported at once, so one deployment attempt shows every missing setting. Only setting
 * names are reported, never their values.
 */
final class EnsureSafeProductionSettings
{
    /** @var list<string> Environments where development defaults are intended and the rules do not apply. */
    public const array DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /** @var list<string> Trusted-proxy entries that trust every peer, or a whole class of peers, instead of explicit ones. */
    private const array WILDCARD_PROXIES = ['*', '**', 'REMOTE_ADDR', 'PRIVATE_SUBNETS'];

    /** @var list<string> Reverb origins that only make sense on a developer machine, plus the allow-all wildcard. */
    private const array DEVELOPMENT_ORIGINS = ['*', 'localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @param string $environment Current application environment name.
     * @param Repository $config Loaded configuration, cached or not.
     * @throws LogicException When the environment is not a development one and at least one rule is violated.
     */
    public function __invoke(string $environment, Repository $config): void
    {
        $violations = $this->violations($environment, $config);

        if ($violations === []) {
            return;
        }

        throw new LogicException(
            'Unsafe settings for the "'.$environment.'" environment: '.implode(' ', $violations),
        );
    }

    /**
     * @param string $environment Current application environment name.
     * @param Repository $config Loaded configuration.
     * @return list<string> One English sentence per violated rule; empty in development environments.
     */
    public function violations(string $environment, Repository $config): array
    {
        if (in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            return [];
        }

        $violations = [];

        if ($config->get('app.debug') === true) {
            $violations[] = 'APP_DEBUG must be false.';
        }

        if ($config->get('session.secure') !== true) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        if ($config->get('mail.default') === 'log') {
            $violations[] = 'MAIL_MAILER must not be log, which writes reset and invitation links to the log.';
        }

        array_push($violations, ...$this->proxyViolations($this->stringList($config->get('status-domains.proxy_ips'))));

        if ($this->stringList($config->get('outbound-security.allowed_cidrs')) !== []) {
            $violations[] = 'OUTBOUND_ALLOWED_CIDRS must be empty, or checks and webhooks can reach private networks.';
        }

        $origins = $this->stringList($config->get('reverb.apps.apps.0.allowed_origins'));

        if (array_intersect($origins, self::DEVELOPMENT_ORIGINS) !== []) {
            $violations[] = 'REVERB_ALLOWED_ORIGINS must list only production hostnames, without localhost, loopback addresses or *.';
        }

        return $violations;
    }

    /**
     * Empty proxies make every client share the proxy's address, so per-IP limits become global; a wildcard or a
     * `/0` range lets any client choose its address with `X-Forwarded-For` and bypass them.
     *
     * @param list<string> $proxies Configured TRUSTED_PROXY_IPS entries.
     * @return list<string> Violations of the explicit-peer rule.
     */
    private function proxyViolations(array $proxies): array
    {
        if ($proxies === []) {
            return ['TRUSTED_PROXY_IPS must list the reverse proxy peers.'];
        }

        foreach ($proxies as $proxy) {
            $wildcard = in_array(strtoupper($proxy), self::WILDCARD_PROXIES, true);
            $everyAddress = str_ends_with($proxy, '/0');

            if ($wildcard || $everyAddress) {
                return ['TRUSTED_PROXY_IPS must list explicit proxy addresses or ranges, not wildcards or /0 ranges.'];
            }
        }

        return [];
    }

    /**
     * @param mixed $value Configuration value expected to be a list of strings.
     * @return list<string> Trimmed non-empty strings; anything else is ignored.
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = array_filter($value, is_string(...));
        $trimmed = array_map(trim(...), $strings);

        return array_values(array_filter($trimmed, static fn (string $item): bool => $item !== ''));
    }
}
