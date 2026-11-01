<?php

declare(strict_types=1);

/** Carry the validated appearance of a status page. */

namespace App\Data\StatusPages;

use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;

/** Stored as `theme_json`; only values from the two enums can exist here. */
final readonly class StatusPageTheme
{
    /**
     * @param StatusPageThemeMode $mode Color scheme.
     * @param StatusPageAccent $accent Decorative accent.
     */
    public function __construct(public StatusPageThemeMode $mode, public StatusPageAccent $accent)
    {
    }

    /** @return array{mode: string, accent: string} Shape stored in `theme_json` and sent to the page. */
    public function toArray(): array
    {
        return ['mode' => $this->mode->value, 'accent' => $this->accent->value];
    }
}
