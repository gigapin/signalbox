<?php

declare(strict_types=1);

/** Incident lifecycle settings shared by automatic opening, resolution and reopening. */
return [
    // Seconds after a resolution during which a new confirmed failure reopens the same incident instead of opening a new one.
    'reopen_window_seconds' => 900,
];
