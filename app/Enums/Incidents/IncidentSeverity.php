<?php

declare(strict_types=1);

/** Classify incident impact, initially derived from the confirmed check failure and later adjustable by people. */

namespace App\Enums\Incidents;

use App\Enums\Monitoring\CheckError;
use InvalidArgumentException;

/** Ordered from least to most severe; the rank drives the choice across disagreeing regional causes. */
enum IncidentSeverity: string
{
    /** Degradation that does not yet block users, such as an expiring certificate. */
    case Minor = 'minor';

    /** The target answers incorrectly: wrong status, content, records or unsafe redirects. */
    case Major = 'major';

    /** The target is unreachable or silent: timeouts, connection, resolution, handshake or missed heartbeat. */
    case Critical = 'critical';

    /**
     * The match is exhaustive, so a new CheckError case cannot ship without an explicit severity.
     *
     * @param CheckError $cause Stable classification of the confirming failure.
     * @return self Severity assigned when an incident is opened automatically.
     */
    public static function forCause(CheckError $cause): self
    {
        return match ($cause) {
            CheckError::Timeout,
            CheckError::Connection,
            CheckError::ResolutionFailed,
            CheckError::Tls,
            CheckError::DnsMissing,
            CheckError::DnsResponse,
            CheckError::HeartbeatMissed => self::Critical,
            CheckError::UnexpectedStatus,
            CheckError::KeywordMismatch,
            CheckError::DnsMismatch,
            CheckError::DnsMalformed,
            CheckError::ResponseTooLarge,
            CheckError::RedirectLimit,
            CheckError::RedirectDowngrade,
            CheckError::InvalidRedirect,
            CheckError::CrossOriginBody,
            CheckError::InvalidDestination,
            CheckError::LocalName,
            CheckError::BlockedAddress => self::Major,
            CheckError::TlsExpiring => self::Minor,
        };
    }

    /**
     * @param self ...$severities Candidate severities, for example one per confirming region.
     * @return self Most severe candidate.
     * @throws InvalidArgumentException When no candidate is supplied.
     */
    public static function max(self ...$severities): self
    {
        if ($severities === []) {
            throw new InvalidArgumentException('At least one severity is required.');
        }

        $highest = $severities[0];

        foreach ($severities as $severity) {
            if ($severity->rank() > $highest->rank()) {
                $highest = $severity;
            }
        }

        return $highest;
    }

    /** @return int Position in the impact order, where a larger value is more severe. */
    public function rank(): int
    {
        return match ($this) {
            self::Minor => 1,
            self::Major => 2,
            self::Critical => 3,
        };
    }
}
