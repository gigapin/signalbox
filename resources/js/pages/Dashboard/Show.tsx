/** Show current tenant operations and measured historical latency, with live recovery and explicit unavailable SLA. */
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../components/app/AppLayout';
import Alert from '../../components/ui/Alert';
import LiveUpdates from '../../components/LiveUpdates';
import MonthlyReportDownload from '../../components/dashboard/MonthlyReportDownload';
import TimeSeriesChart from '../../components/dashboard/TimeSeriesChart';
import type { DashboardProps } from '../../components/dashboard/types';
import OverviewSummary from '../../components/dashboard/OverviewSummary';
import MonitorOverview from '../../components/dashboard/MonitorOverview';
import Panel from '../../components/ui/Panel';
import IncidentTable from '../../components/incidents/IncidentTable';
import { monitorPath } from '../../components/monitors/urls';
import { useLivePage } from '../../hooks/useLivePage';

/** Display nullable service percentages without coercing absent coverage/SLA to a numeric success. */
function percentage(value: number | null): string {
    return value === null ? 'Unavailable' : `${value.toFixed(2)}%`;
}

/** Display whole seconds as hours, minutes and seconds, leaving out leading zero units. */
function duration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${rest}s`;
    }

    return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
}

/** Render the route shell; suspending its children disposes the dashboard's live session. */
export default function Show() {
    const { team } = usePage<DashboardProps>().props;
    const [accessDenied, setAccessDenied] = useState(false);
    return (
        <AppLayout
            title="Dashboard"
            team={team}
            section="dashboard"
            accessDenied={accessDenied}
            description={`${team.name} · application monitoring`}
        >
            <DashboardContent onAccessDenied={setAccessDenied} />
        </AppLayout>
    );
}

/** Denial callback supplied by the outer shell boundary. */
interface DashboardContentProps {
    /** Remove the old team shell after authoritative recovery denies access. */
    onAccessDenied: (denied: boolean) => void;
}

/** Keep live reads inside the removable private-content boundary during a team switch. */
function DashboardContent({ onAccessDenied }: DashboardContentProps) {
    const { team, reportTimezone } = usePage<DashboardProps>().props;
    const live = useLivePage<DashboardProps>(team.id, 'Dashboard/Show');
    useEffect(() => {
        if (live.phase === 'denied') onAccessDenied(true);
    }, [live.phase, onAccessDenied]);
    const { dashboard, rejectedFilters } = live.props;
    const { counts, selectedMonitor, latency, uptime } = dashboard;

    if (live.phase === 'denied') {
        return (
            <Alert title="Team access unavailable" tone="warning" announce="assertive">
                <p>Private data has been hidden. Check your current teams to continue.</p>
                <Link className="ui-link" href="/teams">
                    Your teams
                </Link>
            </Alert>
        );
    }

    return (
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            {rejectedFilters.length > 0 && (
                <p role="alert" className="mb-5 text-amber-200">
                    Some filters were invalid and ignored: {rejectedFilters.join(', ')}.
                </p>
            )}
            <div className="ui-dashboard-stack">
                <OverviewSummary teamId={team.id} dashboard={dashboard} />
                <Panel
                    title="Open incidents"
                    description="Most recent unresolved incidents. Review the timeline to investigate and coordinate a response."
                >
                    {dashboard.incidents.length === 0 ? (
                        <>
                            <p>No open incidents.</p>
                            <p className="ui-help">
                                Monitor status and incident confirmation are separate. Review Down
                                and Unknown monitors even when no incident is open.
                            </p>
                        </>
                    ) : (
                        <IncidentTable
                            teamId={team.id}
                            incidents={dashboard.incidents}
                            caption="Latest open incidents"
                            showMonitor
                        />
                    )}
                    {counts.openIncidents > dashboard.incidents.length && (
                        <p className="ui-description">
                            Showing {dashboard.incidents.length} of {counts.openIncidents} open
                            incidents.
                        </p>
                    )}
                    <Link href={`/teams/${team.id}/incidents?state=open`} className="ui-link">
                        View all open incidents
                    </Link>
                </Panel>
                <Panel
                    title="Monitors"
                    description="Current states and latest check times. Open a monitor for details or select its dashboard charts."
                >
                    <MonitorOverview team={team} dashboard={dashboard} />
                </Panel>
                {selectedMonitor !== null && (
                    <section aria-labelledby="selected-monitor-charts" className="ui-panel">
                        <h2 id="selected-monitor-charts" className="ui-section-title">
                            Charts: {selectedMonitor.name}
                        </h2>
                        <p className="ui-description">
                            Metrics for this monitor only, not a team-wide uptime percentage.
                        </p>
                        <Link className="ui-link" href={monitorPath(team.id, selectedMonitor.id)}>
                            Open monitor details
                        </Link>
                        <p className="mb-5 ui-help">Last 24 hours · Hourly buckets · UTC</p>
                        <div className="grid gap-5 lg:grid-cols-2">
                            <TimeSeriesChart
                                title="Response time"
                                description="Hourly mean of measured checks, including failures with a measured response time. Regions are simulated."
                                timestamps={latency.map((point) => point.at)}
                                series={(['eu', 'us'] as const).map((region) => ({
                                    id: region,
                                    label: region.toUpperCase(),
                                    values: latency.map((point) => point[region].meanMs),
                                }))}
                                unit="ms"
                                empty="No measured response times for this region in the selected window."
                            />
                            <TimeSeriesChart
                                title="Observed uptime"
                                description="Availability weighted by time, with maintenance and paused periods excluded."
                                timestamps={uptime.series.map((point) => point.at)}
                                series={[
                                    {
                                        id: 'uptime',
                                        label: 'Observed uptime',
                                        values: uptime.series.map((point) => point.value),
                                    },
                                ]}
                                unit="%"
                                empty="Time-weighted uptime is unavailable for this window."
                            />
                        </div>
                        <dl
                            aria-label="Availability metrics"
                            className="mt-5 grid gap-3 sm:grid-cols-3"
                        >
                            <div>
                                <dt className="text-sm text-slate-400">Observed uptime</dt>
                                <dd>{percentage(uptime.observedPercent)}</dd>
                            </div>
                            <div>
                                <dt className="text-sm text-slate-400">Data coverage</dt>
                                <dd>{percentage(uptime.coveragePercent)}</dd>
                            </div>
                            <div>
                                <dt className="text-sm text-slate-400">SLA</dt>
                                <dd>{percentage(uptime.slaPercent)}</dd>
                            </div>
                        </dl>
                        {uptime.durations !== null && (
                            <dl
                                aria-label="Availability durations"
                                className="mt-3 grid gap-3 sm:grid-cols-4"
                            >
                                <div>
                                    <dt className="text-sm text-slate-400">Time up</dt>
                                    <dd>{duration(uptime.durations.upSeconds)}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-slate-400">Time down</dt>
                                    <dd>{duration(uptime.durations.downSeconds)}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-slate-400">Time unknown</dt>
                                    <dd>{duration(uptime.durations.unknownSeconds)}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-slate-400">Time excluded</dt>
                                    <dd>{duration(uptime.durations.excludedSeconds)}</dd>
                                </div>
                            </dl>
                        )}
                    </section>
                )}
                <Panel
                    title="Monthly reports"
                    description="Export a monthly SLA report using the team's reporting timezone."
                >
                    <MonthlyReportDownload teamId={team.id} timezone={reportTimezone} />
                </Panel>
            </div>
        </>
    );
}
