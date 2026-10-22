<?php

declare(strict_types=1);

/** Specify whether the configured text must occur in the HTTP response. */

namespace App\Enums\Monitoring;

/** Specify whether the configured text must occur in the HTTP response. */
enum KeywordMode: string
{
    /** Require the configured text to occur. */
    case Present = 'present';

    /** Require the configured text not to occur. */
    case Absent = 'absent';
}
