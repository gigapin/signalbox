<?php

declare(strict_types=1);

/** Name the color schemes a status page can use. */

namespace App\Enums\StatusPages;

/** A closed list, so no submitted value ever reaches the page's styles. */
enum StatusPageThemeMode: string
{
    /** Follow the visitor's operating system preference. */
    case System = 'system';

    /** Always light. */
    case Light = 'light';

    /** Always dark. */
    case Dark = 'dark';
}
