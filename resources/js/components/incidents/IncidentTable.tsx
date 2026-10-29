/** Render incident rows as an accessible table shared by the team list and the monitor page. */
import { Link } from '@inertiajs/react';
import { monitorPath } from '../monitors/urls';
import { IncidentStatusBadge, SeverityBadge } from './IncidentBadges';
import { causeLabel, durationLabel } from './labels';
import { formatInstant } from './time';
import type { IncidentSummary } from './types';
import { incidentPath } from './urls';

/** Table inputs. */
interface IncidentTableProps {
    /** Route team. */
    teamId: number;
    /** Rows to render; callers handle the empty state. */
    incidents: IncidentSummary[];
    /** Visually hidden table caption. */
    caption: string;
    /** Whether to show the monitor column, redundant on a monitor's own page. */
    showMonitor: boolean;
}

/** Each incident links to its timeline; status and severity are text badges. */
export default function IncidentTable({
    teamId,
    incidents,
    caption,
    showMonitor,
}: IncidentTableProps) {
    return (
        <table className="w-full text-left text-sm">
            <caption className="sr-only">{caption}</caption>
            <thead>
                <tr className="border-b border-slate-700">
                    <th scope="col" className="py-2">
                        Incident
                    </th>
                    {showMonitor && <th scope="col">Monitor</th>}
                    <th scope="col">Status</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Cause</th>
                    <th scope="col">Started</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Responsible</th>
                </tr>
            </thead>
            <tbody>
                {incidents.map((incident) => (
                    <tr key={incident.id} className="border-b border-slate-800">
                        <th scope="row" className="py-2 font-medium">
                            <Link
                                href={incidentPath(teamId, incident.id)}
                                className="text-emerald-300 underline"
                            >
                                Incident #{incident.id}
                            </Link>
                        </th>
                        {showMonitor && (
                            <td>
                                <Link
                                    href={monitorPath(teamId, incident.monitor.id)}
                                    className="underline"
                                >
                                    {incident.monitor.name}
                                </Link>
                            </td>
                        )}
                        <td>
                            <IncidentStatusBadge status={incident.status} />
                        </td>
                        <td>
                            <SeverityBadge severity={incident.severity} />
                        </td>
                        <td>{causeLabel(incident.cause)}</td>
                        <td>{formatInstant(incident.startedAt, '')}</td>
                        <td>
                            {incident.durationSeconds === null
                                ? 'Ongoing'
                                : durationLabel(incident.durationSeconds)}
                        </td>
                        <td>{incident.acknowledged ? 'Taken' : 'Nobody yet'}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
