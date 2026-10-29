<?php

declare(strict_types=1);

/** Describe a TLS destination and optional expiry warning without opening a socket. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Certificate, chain and hostname verification cannot be disabled through this configuration. */
final readonly class TlsMonitorConfiguration implements MonitorConfiguration
{
    /**
     * @param string $host Nonempty certificate hostname, subject to later destination validation.
     * @param int $port Explicit TCP port from 1 through 65535.
     * @param int|null $expiryWarningDays Nonnegative warning window in days; null requests validity/expiry checking only.
     * @throws InvalidArgumentException When a required value or numeric range is invalid.
     */
    public function __construct(public string $host, public int $port, public ?int $expiryWarningDays = null)
    {
        if (trim($host) === '' || $port < 1 || $port > 65535 || ($expiryWarningDays !== null && $expiryWarningDays < 0)) {
            throw new InvalidArgumentException('TLS configuration requires a host, valid port and nonnegative warning window.');
        }
    }

    /** @return MonitorType TLS observation identity. */
    public function type(): MonitorType
    {
        return MonitorType::Tls;
    }
}
