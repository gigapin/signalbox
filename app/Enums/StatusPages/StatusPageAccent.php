<?php

declare(strict_types=1);

/** Name the decorative accent colors a status page can use. */

namespace App\Enums\StatusPages;

/** Presets rather than free colors: no submitted value reaches the styles, and text contrast never depends on them. */
enum StatusPageAccent: string
{
    /** Green, the product default. */
    case Emerald = 'emerald';

    /** Blue. */
    case Blue = 'blue';

    /** Violet. */
    case Violet = 'violet';

    /** Amber. */
    case Amber = 'amber';

    /** Neutral gray. */
    case Slate = 'slate';
}
