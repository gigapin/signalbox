<?php

declare(strict_types=1);

/** Explicit upgrade compatibility; disable only after the documented migration and expiry checks. */

return [
    // Previous derived keys remain readable through APP_PREVIOUS_KEYS independently of this legacy switch.
    'allow_legacy' => (bool) env('CRYPTOGRAPHY_ALLOW_LEGACY', true),
];
