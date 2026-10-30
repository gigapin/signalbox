/** Show current tenant operations and measured historical latency, with live recovery and explicit unavailable SLA. */
import { Link, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/auth/AuthLayout';
import LiveUpdates from '../../components/LiveUpdates';
import TimeSeriesChart from '../../components/dashboard/TimeSeriesChart';
import { dashboardUrl, type DashboardProps } from '../../components/dashboard/types';
import IncidentTable from '../../components/incidents/IncidentTable';
import { StateBadge, StatusBadge } from '../../components/monitors/MonitorBadges';
import Pagination from '../../components/monitors/Pagination';
import { monitorPath, monitorsPath } from '../../components/monitors/urls';
import { useLivePage } from '../../hooks/useLivePage';

/** Display nullable service percentages without coercing absent coverage/SLA to a numeric success. */
function percentage(value: number | null): string {
    return value === null ? 'Unavailable' : `${value.toFixed(2)}%`;
}

/** Render one route team's dashboard; URL selection is carried unchanged into event-driven recovery. */
export default function Show() {
    const { team } = usePage<DashboardProps>().props;
    const live = useLivePage<DashboardProps>(team.id, 'Dashboard/Show');
    const { dashboard, rejectedFilters } = live.props;
    const { counts, monitors, selectedMonitor, latency, uptime } = dashboard;

    return (
        <AuthLayout title="Dashboard" description={`${team.name} · application monitoring`} wide>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <nav
                aria-label="Team navigation"
                className="mb-6 flex flex-wrap gap-4 text-sm text-emerald-300"
            >
                <Link href={monitorsPath(team.id)}>All monitors</Link>
                <Link href={`/teams/${team.id}/incidents`}>All incidents</Link>
                <Link href={`/teams/${team.id}/notifications`}>Notifications</Link>
                <Link href={`/teams/${team.id}`}>Team members</Link>
            </nav>
            {rejectedFilters.length > 0 && (
                <p role="alert" className="mb-5 text-amber-200">
                    Some filters were invalid and ignored: {rejectedFilters.join(', ')}.
                </p>
            )}
            <p className="mb-4 text-sm text-slate-400">
                {counts.total} monitors · Updated{' '}
                <time dateTime={dashboard.generatedAt}>
                    {new Date(dashboard.generatedAt).toLocaleString()}
                </time>
            </p>
            <dl
                aria-label="Monitor states"
                className="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
            >
                {(['up', 'down', 'unknown', 'paused', 'suspended'] as const).map((state) => (
                    <div key={state} className="rounded border border-slate-700 p-4">
                        <dt className="capitalize text-slate-400">{state}</dt>
                        <dd className="mt-2 text-2xl font-semibold">{counts[state]}</dd>
                    </div>
                ))}
                <div className="rounded border border-slate-700 p-4">
                    <dt className="text-slate-400">Open incidents</dt>
                    <dd className="mt-2 text-2xl font-semibold">{counts.openIncidents}</dd>
                </div>
            </dl>
            <section aria-labelledby="dashboard-incidents" className="mb-8">
                <h2 id="dashboard-incidents" className="mb-3 text-xl font-semibold">
                    Open incidents
                </h2>
                {dashboard.incidents.length === 0 ? (
                    <p className="text-slate-400">No open incidents.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <IncidentTable
                            teamId={team.id}
                            incidents={dashboard.incidents}
                            caption="Latest open incidents"
                            showMonitor
                        />
                    </div>
                )}
                {counts.openIncidents > dashboard.incidents.length && (
                    <Link
                        href={`/teams/${team.id}/incidents?state=open`}
                        className="mt-3 inline-block text-emerald-300 underline"
                    >
                        View all open incidents
                    </Link>
                )}
            </section>
            <section aria-labelledby="dashboard-monitors" className="mb-8">
                <h2 id="dashboard-monitors" className="mb-3 text-xl font-semibold">
                    Monitors
                </h2>
                {counts.total === 0 ? (
                    <p className="text-slate-300">
                        No monitors yet.{' '}
                        <Link href={monitorsPath(team.id)} className="text-emerald-300 underline">
                            Manage monitors
                        </Link>
                    </p>
                ) : (
                    <>
                        {monitors.data.length === 0 ? (
                            <p>
                                No monitors on this page.{' '}
                                <Link
                                    className="text-emerald-300 underline"
                                    href={dashboardUrl(team.id)}
                                >
                                    Back to the first page
                                </Link>
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">Monitors of {team.name}</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Monitor</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">State</th>
                                            <th scope="col">Charts</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {monitors.data.map((monitor) => (
                                            <tr
                                                key={monitor.id}
                                                className="border-b border-slate-800"
                                            >
                                                <th scope="row" className="py-3 font-normal">
                                                    <Link
                                                        href={monitorPath(team.id, monitor.id)}
                                                        className="text-emerald-300 underline"
                                                    >
                                                        {monitor.name}
                                                    </Link>
                                                </th>
                                                <td>
                                                    <StatusBadge status={monitor.lastStatus} />
                                                </td>
                                                <td>
                                                    <StateBadge state={monitor.state} />
                                                </td>
                                                <td>
                                                    <Link
                                                        href={dashboardUrl(
                                                            team.id,
                                                            monitors.currentPage,
                                                            monitor.id,
                                                        )}
                                                        aria-current={
                                                            selectedMonitor?.id === monitor.id
                                                                ? 'true'
                                                                : undefined
                                                        }
                                                        className="text-emerald-300 underline"
                                                    >
                                                        {selectedMonitor?.id === monitor.id
                                                            ? 'Selected'
                                                            : `View charts for ${monitor.name}`}
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <Pagination
                            current={monitors.currentPage}
                            last={monitors.lastPage}
                            href={(page) => dashboardUrl(team.id, page, selectedMonitor?.id)}
                        />
                    </>
                )}
            </section>
            <h2 className="mb-2 text-xl font-semibold">
                {selectedMonitor?.name ?? 'Monitor charts'}
            </h2>
            <p className="mb-5 text-sm text-slate-400">Last 24 hours · Hourly buckets · UTC</p>
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
            <dl aria-label="Availability metrics" className="mt-5 grid gap-3 sm:grid-cols-3">
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
        </AuthLayout>
    );
}
