<?php

declare(strict_types=1);

/** Model a daily local-wall-clock silence interval without constructing ambiguous DST instants. */

namespace App\Data\Notifications;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/** Immutable minute-resolution interval [start, end); a later start wraps through midnight. */
final readonly class QuietHours
{
    /** @var string Strict 24-hour HH:mm syntax, shared with the request and persistence boundary. */
    public const string TIME_PATTERN = '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/';

    /**
     * @param string $start Inclusive local time, exactly HH:mm.
     * @param string $end Exclusive local time, exactly HH:mm and distinct from start.
     * @param string $timezone Explicit IANA timezone, including UTC; fixed numeric offsets are refused.
     * @throws InvalidArgumentException When either time, the interval or the timezone is invalid.
     */
    public function __construct(public string $start, public string $end, public string $timezone)
    {
        if (preg_match(self::TIME_PATTERN, $start) !== 1
            || preg_match(self::TIME_PATTERN, $end) !== 1
            || $start === $end
            || !in_array($timezone, self::timezones(), true)) {
            throw new InvalidArgumentException('Quiet hours require distinct HH:mm times and an IANA timezone.');
        }
    }

    /** @return list<string> Supported named timezones from the runtime's timezone database. */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    }

    /**
     * @param DateTimeInterface $at Real instant, in any timezone; never mutated.
     * @return bool Whether its local wall time lies in the daily interval, including both repeated DST occurrences.
     */
    public function contains(DateTimeInterface $at): bool
    {
        $local = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone($this->timezone))->format('H:i');

        if ($this->start < $this->end) {
            return $local >= $this->start && $local < $this->end;
        }

        return $local >= $this->start || $local < $this->end;
    }

    /**
     * @param DateTimeInterface $at Real instant at which delivery is requested; never mutated.
     * @return DateTimeImmutable First permitted UTC instant, or the input instant when already outside quiet hours.
     * @throws LogicException If the named timezone has no permitted boundary within three days.
     */
    public function nextAllowedAt(DateTimeInterface $at): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $instant = DateTimeImmutable::createFromInterface($at)->setTimezone($utc);

        if (!$this->contains($instant)) {
            return $instant;
        }

        $zone = new DateTimeZone($this->timezone);
        $limit = $instant->modify('+3 days')->getTimestamp();
        $transitions = $zone->getTransitions($instant->getTimestamp(), $limit);

        $candidates = [];
        $offsets = [];

        foreach ($transitions as $transition) {
            $offsets[] = $transition['offset'];
            $candidates[] = $transition['ts'];
        }

        // Resolve every possible offset explicitly: PHP's local-time parser would choose one repeated hour or normalize a missing one.
        $date = new DateTimeImmutable($instant->setTimezone($zone)->format('Y-m-d').' '.$this->end, $utc);

        foreach (range(0, 3) as $day) {
            $localEnd = $date->modify('+'.$day.' days')->getTimestamp();

            foreach (array_unique($offsets) as $offset) {
                $candidates[] = $localEnd - $offset;
            }
        }

        sort($candidates, SORT_NUMERIC);

        foreach ($candidates as $timestamp) {
            if ($timestamp <= $instant->getTimestamp() || $timestamp > $limit) {
                continue;
            }

            $candidate = (new DateTimeImmutable('@'.$timestamp))->setTimezone($utc);

            if (!$this->contains($candidate)) {
                return $candidate;
            }
        }

        throw new LogicException('No permitted quiet-hours boundary was found within three days.');
    }
}
