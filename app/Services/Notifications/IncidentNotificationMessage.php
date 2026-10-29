<?php

declare(strict_types=1);

/** Select minimal incident facts for the intended notification recipient without network targets or private timeline notes. */

namespace App\Services\Notifications;

use App\Data\Notifications\ChannelMessage;
use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Support\Str;
use LogicException;

/** Immutable snapshots keep retries byte-stable even if people subsequently change incident text or monitor configuration. */
final class IncidentNotificationMessage
{
    /**
     * @param Incident $incident Locked incident after the transition.
     * @param Monitor $monitor Locked monitor of the same tenant.
     * @param string $event Controlled incident lifecycle event.
     * @return ChannelMessage Fresh UUID and event-time snapshot, without URL, headers, body, root-cause note or timeline text.
     * @throws LogicException When the transition has no matching event instant.
     */
    public function make(Incident $incident, Monitor $monitor, string $event): ChannelMessage
    {
        $at = match ($event) {
            'incident.opened' => $incident->confirmed_at,
            'incident.reopened' => $incident->last_reopened_at,
            'incident.resolved' => $incident->resolved_at,
            default => null,
        };

        if ($at === null) {
            throw new LogicException('An incident notification requires its transition instant.');
        }

        $state = match ($event) {
            'incident.resolved' => 'resolved',
            'incident.reopened' => 'reopened',
            default => 'opened',
        };
        $name = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $monitor->name) ?? 'Monitor';
        $name = mb_substr($name, 0, 200);
        $text = 'Signalbox: incident #'.$incident->id.' '.$state.' for "'.$name.'". Severity: '.$incident->severity->value.'. Cause: '.$incident->cause->value.'.';

        if ($event === 'incident.resolved') {
            $text .= ' Duration: '.$incident->duration_seconds.' seconds. Reopenings: '.$incident->reopened_count.'.';
        }

        return new ChannelMessage(
            (string) Str::uuid(),
            $event,
            $at,
            'Signalbox incident '.$state,
            $text,
            [
                'incident_id' => $incident->id,
                'monitor_id' => $monitor->id,
                'cycle' => $incident->reopened_count,
                'severity' => $incident->severity->value,
                'cause' => $incident->cause->value,
                'started_at' => $incident->started_at->toIso8601String(),
                'confirmed_at' => $incident->confirmed_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'duration_seconds' => $incident->duration_seconds,
                'reopened_count' => $incident->reopened_count,
            ],
        );
    }
}
