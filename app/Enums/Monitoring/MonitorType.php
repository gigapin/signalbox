<?php

declare(strict_types=1);

/** Identify the supported monitor configuration and future checker strategy. */

namespace App\Enums\Monitoring;

/** Identify the supported monitor configuration and future checker strategy. */
enum MonitorType: string
{
    /** Observe an HTTP response. */
    case Http = 'http';

    /** Observe certificate validity and expiry. */
    case Tls = 'tls';

    /** Compare DNS records. */
    case Dns = 'dns';

    /** Observe the receipt of an expected external signal. */
    case Heartbeat = 'heartbeat';

    /** Compare text in an HTTP response. */
    case Keyword = 'keyword';
}
