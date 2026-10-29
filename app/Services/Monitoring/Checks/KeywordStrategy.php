<?php

declare(strict_types=1);

/** Evaluate exact keyword bytes after requiring the configured HTTP status. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\HttpResponseStrategy;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HttpObservation;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorStatus;
use InvalidArgumentException;

/** Presence and absence remain case-sensitive binary string operations, never regex matches. */
final class KeywordStrategy implements HttpResponseStrategy
{
    /**
     * @param CheckInput $input Keyword configuration and correlation identity.
     * @param HttpObservation $observation Complete bounded final response; never a truncated prefix.
     * @return CheckOutcome Status-first text evaluation without exposing either configured or received text.
     * @throws InvalidArgumentException When the configuration is not keyword-based.
     */
    public function evaluate(CheckInput $input, #[\SensitiveParameter] HttpObservation $observation): CheckOutcome
    {
        $configuration = $input->configuration;

        if (!$configuration instanceof KeywordMonitorConfiguration) {
            throw new InvalidArgumentException('The keyword strategy requires keyword configuration.');
        }

        $error = null;

        if ($observation->statusCode !== $configuration->http->expectedStatus) {
            $error = CheckError::UnexpectedStatus;
        } else {
            $present = str_contains($observation->body, $configuration->keyword);

            if ($present !== ($configuration->mode === KeywordMode::Present)) {
                $error = CheckError::KeywordMismatch;
            }
        }

        return new CheckOutcome(
            $input->context,
            $error === null ? MonitorStatus::Up : MonitorStatus::Down,
            $observation->observedAt,
            $observation->latencyMs,
            $observation->statusCode,
            $error,
        );
    }
}
