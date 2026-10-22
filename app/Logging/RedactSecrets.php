<?php

declare(strict_types=1);

/** Attach secret redaction to a configured log channel through Laravel's channel tap hook. */

namespace App\Logging;

use App\Services\Security\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/** Listed in each concrete channel of config/logging.php; stack channels inherit it from their members. */
final readonly class RedactSecrets
{
    /** @param Logger $logger Channel being created; non-Monolog drivers are left unchanged. */
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new SecretRedactionProcessor(new SecretRedactor()));
        }
    }
}
