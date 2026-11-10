<?php

declare(strict_types=1);

/** Project only a monitor's safe destination without hydrating headers, bodies or keyword expectations. */

namespace App\Services\Monitoring\ReadModels;

use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use App\Services\Security\SecretRedactor;

/** HTTP URLs are decrypted individually for at most one visible page and immediately redacted. */
final readonly class MonitorDestination
{
    /** @param SecretRedactor $redactor Shared URL redaction authority. */
    public function __construct(private SecretRedactor $redactor)
    {
    }

    /**
     * @param Monitor $monitor Visible monitor with destination columns explicitly loaded.
     * @return string|null Redacted HTTP URL, public TLS/DNS name, or null for heartbeat/unknown type.
     */
    public function read(Monitor $monitor): ?string
    {
        return match ($monitor->type) {
            MonitorType::Http, MonitorType::Keyword => $monitor->url === null ? null : $this->redactor->url($monitor->url),
            MonitorType::Tls => $monitor->tls_host,
            MonitorType::Dns => $monitor->dns_name,
            default => null,
        };
    }
}
