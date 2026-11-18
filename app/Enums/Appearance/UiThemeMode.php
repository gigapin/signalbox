<?php

/** Explicit user appearance choices; operating-system mode is deliberately unsupported. */

namespace App\Enums\Appearance;

/** Persist only intentional light or dark choices, with null retaining the dark default. */
enum UiThemeMode: string
{
    /** Original application appearance and default. */
    case Dark = 'dark';

    /** Light canvas with readable green accents. */
    case Light = 'light';
}
