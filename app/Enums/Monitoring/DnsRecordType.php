<?php

declare(strict_types=1);

/** Identify supported DNS record comparisons. */

namespace App\Enums\Monitoring;

/** Identify supported DNS record comparisons. */
enum DnsRecordType: string
{
    /** Compare A DNS records. */
    case A = 'A';

    /** Compare AAAA DNS records. */
    case AAAA = 'AAAA';

    /** Compare CNAME DNS records. */
    case CNAME = 'CNAME';

    /** Compare MX DNS records. */
    case MX = 'MX';

    /** Compare TXT DNS records. */
    case TXT = 'TXT';
}
