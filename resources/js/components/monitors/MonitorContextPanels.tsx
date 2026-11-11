/** Present safe observed context using server phases and provenance, without inferring delivery or current certificate validity. */
import { Link } from '@inertiajs/react';
import Panel from '../ui/Panel';
import type { MonitorContextView } from './metrics';
import type { IncidentSummary, MaintenanceView, MaintenanceWindowView } from '../incidents/types';
import { causeLabel, durationLabel, incidentStatusLabel, severityLabel } from '../incidents/labels';
import { incidentPath } from '../incidents/urls';

/** Authorized, bounded context collections from the same monitor snapshot. */
interface ContextProps {
    /** Route team used for incident links. */
    teamId: number;
    /** Absent only on older history entries; never interpreted as an empty configuration. */
    context: MonitorContextView | undefined;
    /** Windows with phases determined by the server clock. */
    maintenance: MaintenanceView;
    /** Latest five incidents, in server order. */
    incidents: IncidentSummary[];
}

/**
 * @param props UTC instant supplied by the server.
 * @returns A localized timestamp whose machine value remains UTC.
 */
function LocalTime({ at }: { at: string }) {
    return <time dateTime={at}>{new Date(at).toLocaleString()}</time>;
}

/**
 * @param props Ongoing or upcoming server-selected windows.
 * @returns Explicit local boundaries and reasons, including overlapping current windows.
 */
function Windows({ windows }: { windows: MaintenanceWindowView[] }) {
    return (
        <ul className="ui-context-list">
            {windows.map((window) => (
                <li key={window.id}>
                    <p>{window.reason}</p>
                    <p className="ui-help">
                        <LocalTime at={window.startsAt} /> → <LocalTime at={window.endsAt} />
                    </p>
                </li>
            ))}
        </ul>
    );
}

/**
 * @param incident Server projection; a null final duration is never calculated from the browser clock.
 * @returns Honest final duration or unresolved state.
 */
function incidentDuration(incident: IncidentSummary): string {
    if (incident.durationSeconds !== null)
        return `Duration: ${durationLabel(incident.durationSeconds)}`;
    if (incident.resolvedAt === null) return 'Ongoing; final duration unavailable';
    return 'Final duration unavailable';
}

/**
 * @param props Authorized context and existing bounded collections.
 * @returns Named panels with honest missing, stale, unconfigured and non-applicable states.
 */
export default function MonitorContextPanels({
    teamId,
    context,
    maintenance,
    incidents,
}: ContextProps) {
    const certificate = context?.certificate;
    const policy = context?.notifications;
    const current = maintenance.current.filter((window) => window.phase === 'ongoing');
    const next = maintenance.current.find((window) => window.phase === 'upcoming');

    return (
        <div className="ui-monitor-context">
            <Panel
                title="TLS certificate"
                description="Certificate timestamps use your local time."
            >
                {certificate === undefined ? (
                    <p>Certificate context unavailable. Reload to retrieve the current snapshot.</p>
                ) : certificate.state === 'not_applicable' ? (
                    <p>Not applicable to this monitor type.</p>
                ) : certificate.state === 'missing' ? (
                    <p>No certificate expiry observation available.</p>
                ) : (
                    <>
                        <p>
                            {certificate.state === 'current'
                                ? 'Recent observation'
                                : 'Stale observation'}
                        </p>
                        <dl className="ui-monitor-facts">
                            <div>
                                <dt>Observed expiry</dt>
                                <dd>
                                    {certificate.expiresAt === null ? (
                                        'Missing expiry data'
                                    ) : (
                                        <LocalTime at={certificate.expiresAt} />
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt>Certificate observed at</dt>
                                <dd>
                                    {certificate.observedAt === null ? (
                                        'Observation provenance no longer available'
                                    ) : (
                                        <LocalTime at={certificate.observedAt} />
                                    )}
                                </dd>
                            </div>
                        </dl>
                        <p className="ui-help">
                            {certificate.state === 'stale'
                                ? 'Cached expiry may be outdated. A stale observation does not mean the certificate has expired.'
                                : 'Observed within two check intervals at snapshot generation. This does not guarantee current certificate validity.'}
                        </p>
                    </>
                )}
            </Panel>
            <Panel
                title="Maintenance overview"
                description="Window boundaries use your local time; phases follow the server snapshot."
            >
                <h3 className="ui-section-title">Current maintenance</h3>
                {current.length === 0 ? (
                    <p>No maintenance currently in progress.</p>
                ) : (
                    <Windows windows={current} />
                )}
                <h3 className="ui-section-title">Next maintenance</h3>
                {next === undefined ? (
                    <p>No upcoming maintenance scheduled.</p>
                ) : (
                    <Windows windows={[next]} />
                )}
                <p className="ui-help">
                    Manage windows and view recent history in Maintenance below.
                </p>
            </Panel>
            <Panel
                title="Effective notifications"
                description="Configured policy and channel eligibility at snapshot generation."
            >
                {policy === undefined ? (
                    <p>
                        Notification context unavailable. Reload to retrieve the current snapshot.
                    </p>
                ) : policy === null ? (
                    <p>No effective notification policy configured.</p>
                ) : (
                    <>
                        <p>
                            {policy.name} (
                            {policy.source === 'default' ? 'Team default' : 'Monitor override'})
                        </p>
                        {policy.steps.length === 0 && <p>No escalation steps configured.</p>}
                        <ol className="ui-context-list">
                            {policy.steps.map((step, index) => (
                                <li key={index}>
                                    <h3 className="font-medium">
                                        {step.afterMinutes === 0
                                            ? 'At incident opening'
                                            : `After ${step.afterMinutes} minutes from incident opening`}
                                    </h3>
                                    {step.channels.length === 0 ? (
                                        <p>No available channels in this step.</p>
                                    ) : (
                                        <ul className="ui-context-list">
                                            {step.channels.map((channel) => (
                                                <li key={channel.id}>
                                                    {channel.name} · {channel.type}
                                                    <p className="ui-help">
                                                        {channel.isActive ? 'Active' : 'Inactive'} ·{' '}
                                                        {channel.isVerified
                                                            ? 'Verified'
                                                            : 'Unverified'}
                                                        {channel.isStub &&
                                                            ' · Stub: no messages sent'}
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ol>
                        <p className="ui-help">
                            Configuration does not guarantee delivery. Quiet hours, maintenance,
                            deduplication and delivery rules still apply.
                        </p>
                    </>
                )}
            </Panel>
            <Panel
                title="Recent incidents"
                description="Latest incidents for this monitor. Start times use your local time."
            >
                {incidents.length === 0 ? (
                    <p>No incidents recorded for this monitor.</p>
                ) : (
                    <ul className="ui-context-list">
                        {incidents.map((incident) => (
                            <li key={incident.id}>
                                <Link className="ui-link" href={incidentPath(teamId, incident.id)}>
                                    Incident #{incident.id}
                                </Link>
                                <p>
                                    {incidentStatusLabel(incident.status)} ·{' '}
                                    {severityLabel(incident.severity)} ·{' '}
                                    {causeLabel(incident.cause)}
                                </p>
                                <p className="ui-help">
                                    Started <LocalTime at={incident.startedAt} />
                                </p>
                                <p>{incidentDuration(incident)}</p>
                                <p className="ui-help">
                                    {incident.acknowledged
                                        ? 'Responsibility taken'
                                        : 'Nobody assigned yet'}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}
