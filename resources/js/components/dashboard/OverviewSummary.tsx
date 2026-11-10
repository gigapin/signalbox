/** Present team-wide operational counts and safe shortcuts without inferring health from missing data. */
import { Link } from '@inertiajs/react';
import { monitorsPath } from '../monitors/urls';
import type { DashboardView } from './types';

/** Operational overview inputs from the authorized route and current live snapshot. */
interface OverviewSummaryProps {
    /** Explicit route team, independent of the saved preference. */
    teamId: number;
    /** Current counters, projection instant and permission/plan hints. */
    dashboard: DashboardView;
}

/** Text and semantic tone for disjoint monitor counters; active status filters exclude paused/suspended rows. */
const STATES = [
    { key: 'down', label: 'Down', tone: 'danger', query: 'state=active&status=down' },
    { key: 'unknown', label: 'Unknown', tone: 'neutral', query: 'state=active&status=unknown' },
    { key: 'up', label: 'Up', tone: 'success', query: 'state=active&status=up' },
    { key: 'paused', label: 'Paused', tone: 'warning', query: 'state=paused' },
    { key: 'suspended', label: 'Suspended', tone: 'danger', query: 'state=suspended' },
] as const;

/**
 * @param props Team-wide state, timestamp and read-only quota snapshot.
 * @returns Linked counters and role-aware creation guidance; no mutation or aggregate uptime calculation.
 */
export default function OverviewSummary({ teamId, dashboard }: OverviewSummaryProps) {
    const { counts, generatedAt, can, usage } = dashboard;
    return (
        <section aria-label="Team overview" className="ui-overview">
            <div className="ui-overview-toolbar">
                <div>
                    <p className="ui-overview-total">{counts.total} monitors</p>
                    <p className="ui-help">
                        Snapshot generated{' '}
                        <time dateTime={generatedAt}>{new Date(generatedAt).toLocaleString()}</time>
                    </p>
                    <p className="ui-help">
                        Latest check times are shown per monitor below. Times use your local
                        timezone.
                    </p>
                </div>
                <div className="ui-actions">
                    <Link
                        className="ui-button"
                        data-variant="secondary"
                        href={monitorsPath(teamId)}
                    >
                        Manage monitors
                    </Link>
                    {can?.create === true && usage?.full === false && (
                        <Link className="ui-button" href={`${monitorsPath(teamId)}/create`}>
                            New monitor
                        </Link>
                    )}
                </div>
            </div>
            <dl aria-label="Monitor states" className="ui-overview-counts">
                <div
                    className="ui-overview-count"
                    data-tone={counts.openIncidents > 0 ? 'danger' : 'neutral'}
                >
                    <dt>
                        <Link className="ui-link" href={`/teams/${teamId}/incidents?state=open`}>
                            Open incidents
                        </Link>
                    </dt>
                    <dd>{counts.openIncidents}</dd>
                </div>
                {STATES.map(({ key, label, tone, query }) => (
                    <div className="ui-overview-count" data-tone={tone} key={key}>
                        <dt>
                            <Link className="ui-link" href={`${monitorsPath(teamId)}?${query}`}>
                                {label}
                            </Link>
                        </dt>
                        <dd>{counts[key]}</dd>
                    </div>
                ))}
            </dl>
            <p className="ui-help">
                Up, Down and Unknown count active monitors. Paused and suspended monitors are
                counted separately. Unknown means no known current result.
            </p>
            {usage && (
                <p className="ui-help">
                    Plan usage: {usage.used} of {usage.limit} monitors · Minimum check interval:{' '}
                    {usage.minimumIntervalSeconds} seconds. Paused and suspended monitors also use
                    quota.
                </p>
            )}
            {can?.create === true && usage?.full === true && (
                <p className="ui-description">
                    Monitor limit reached. Review your existing monitors before adding another.
                </p>
            )}
        </section>
    );
}
