/** Present a bounded monitor snapshot and preserve legacy dashboard chart/page selection URLs. */
import { Link } from '@inertiajs/react';
import DataTable from '../ui/DataTable';
import { StateBadge, StatusBadge } from '../monitors/MonitorBadges';
import Pagination from '../monitors/Pagination';
import { monitorPath } from '../monitors/urls';
import type { TeamSummary } from '../monitors/types';
import { dashboardUrl, type DashboardView } from './types';

/** Snapshot identity and accepted dashboard query state. */
interface MonitorOverviewProps {
    /** Authorized route identity for every link. */
    team: TeamSummary;
    /** Current bounded rows, plan/permission hints and selected chart identity. */
    dashboard: DashboardView;
}

/**
 * @param props Team and read-only dashboard snapshot.
 * @returns Empty-state guidance or paginated native table with resource/legacy chart links.
 */
export default function MonitorOverview({ team, dashboard }: MonitorOverviewProps) {
    const { counts, monitors, selectedMonitor, can, usage } = dashboard;
    let nextStep =
        'Open monitor management to review your access and plan before adding the first monitor.';
    if (can?.create === true && usage?.full === false) {
        nextStep =
            'Use New monitor above when your plan has capacity. Choose the check type and an interval allowed by your plan.';
    } else if (can?.create === false) {
        nextStep =
            'Ask a team owner, admin or member to add the first monitor. You can read its results here once it is configured.';
    }
    if (counts.total === 0) {
        return (
            <div className="ui-overview-empty">
                <h3 className="ui-section-title">No monitors yet</h3>
                <p>
                    Add a monitor to start observing availability and response times. No checks or
                    uptime data exist for this team yet.
                </p>
                <p>{nextStep}</p>
            </div>
        );
    }
    return (
        <>
            {monitors.data.length === 0 ? (
                <p>
                    No monitors on this page.{' '}
                    <Link className="ui-link" href={dashboardUrl(team.id, 1, selectedMonitor?.id)}>
                        Back to the first page
                    </Link>
                </p>
            ) : (
                <DataTable caption={`Monitors of ${team.name}`}>
                    <thead>
                        <tr>
                            <th scope="col">Monitor</th>
                            <th scope="col">Status</th>
                            <th scope="col">State</th>
                            <th scope="col">Last check (local time)</th>
                            <th scope="col">Charts</th>
                        </tr>
                    </thead>
                    <tbody>
                        {monitors.data.map((monitor) => (
                            <tr key={monitor.id}>
                                <th scope="row">
                                    <Link
                                        href={monitorPath(team.id, monitor.id)}
                                        className="ui-link"
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
                                    {monitor.lastCheckedAt === null ? (
                                        'No check recorded'
                                    ) : (
                                        <time dateTime={monitor.lastCheckedAt}>
                                            {new Date(monitor.lastCheckedAt).toLocaleString()}
                                        </time>
                                    )}
                                </td>
                                <td>
                                    <Link
                                        href={dashboardUrl(
                                            team.id,
                                            monitors.currentPage,
                                            monitor.id,
                                        )}
                                        aria-current={
                                            selectedMonitor?.id === monitor.id ? 'true' : undefined
                                        }
                                        className="ui-link"
                                    >
                                        {selectedMonitor?.id === monitor.id
                                            ? 'Selected'
                                            : `View charts for ${monitor.name}`}
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            )}
            <Pagination
                current={monitors.currentPage}
                last={monitors.lastPage}
                href={(page) => dashboardUrl(team.id, page, selectedMonitor?.id)}
            />
        </>
    );
}
