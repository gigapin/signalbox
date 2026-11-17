<?php

declare(strict_types=1);

/** Protect Laravel's fallback logger, which bypasses configured channel taps. */

namespace App\Logging;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

/**
 * Keep the framework's emergency transport while applying the ordinary redaction boundary.
 * Remain extensible like Laravel's manager so facade spies and mocks can substitute this service.
 */
class RedactingLogManager extends LogManager
{
    /**
     * @param string|null $name Channel name selected by the framework.
     * @param Logger $logger Newly created channel before shared context is attached.
     * @return Logger Channel preserving throwable message identity until redaction.
     */
    protected function tap($name, Logger $logger): Logger
    {
        $logger = parent::tap($name, $logger);

        return new RedactingLogger($logger->getLogger(), $logger->getEventDispatcher());
    }

    /** @return Logger Emergency logger protected before even the channel-creation error is written. */
    protected function createEmergencyLogger(): Logger
    {
        $original = parent::createEmergencyLogger();
        $logger = new RedactingLogger(
            $original instanceof Logger ? $original->getLogger() : $original,
            $this->app->make(Dispatcher::class),
        );
        (new RedactSecrets())($logger);

        return $logger;
    }
}
