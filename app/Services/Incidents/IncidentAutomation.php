<?php

declare(strict_types=1);

/** Open, reopen or resolve a monitor's incident from a joint slot verdict. */

namespace App\Services\Incidents;

use App\Data\Incidents\SlotVerdict;
use App\Enums\Incidents\SlotVerdictKind;
use App\Models\Incident;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Called under the terminal transaction's Team → Monitor locks; incidents are then locked in turn. Repeated
 * verdicts are idempotent: a confirmation with an open incident and a recovery without one change nothing.
 */
final readonly class IncidentAutomation
{
    /**
     * @param IncidentLifecycle $lifecycle State changes with timeline entries and events.
     * @param Repository $config Source of the reopen window.
     */
    public function __construct(private IncidentLifecycle $lifecycle, private Repository $config)
    {
    }

    /**
     * @param Monitor $monitor Locked monitor of the evaluated slot.
     * @param SlotVerdict $verdict Joint verdict of that slot.
     * @param CarbonImmutable $now UTC instant of the terminal transaction.
     */
    public function apply(Monitor $monitor, SlotVerdict $verdict, CarbonImmutable $now): void
    {
        if ($verdict->kind === SlotVerdictKind::Inconclusive) {
            return;
        }

        $open = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->whereNull('resolved_at')
            ->lockForUpdate()
            ->first();

        if ($verdict->kind === SlotVerdictKind::Recovered) {
            if ($open !== null) {
                $this->lifecycle->resolve($open, null, 'Recovery observed in every configured region; the incident was resolved automatically.', $now);
            }

            return;
        }

        if ($open !== null || $verdict->startedAt === null || $verdict->cause === null) {
            return;
        }

        $recent = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('resolved_at', '>=', $now->subSeconds($this->reopenWindowSeconds()))
            ->orderByDesc('resolved_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($recent !== null) {
            $this->lifecycle->reopen($recent, $now);

            return;
        }

        $this->lifecycle->open($monitor, $verdict->startedAt, $verdict->cause, $now);
    }

    /**
     * @return int Positive reopen window in seconds.
     * @throws LogicException When the configuration is missing or not a positive integer.
     */
    private function reopenWindowSeconds(): int
    {
        $seconds = $this->config->get('incidents.reopen_window_seconds');

        if (!is_int($seconds) || $seconds < 1) {
            throw new LogicException('incidents.reopen_window_seconds must be a positive integer.');
        }

        return $seconds;
    }
}
