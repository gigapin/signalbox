/** Describe the explicit operational dashboard projection without treating sample counts as time-weighted uptime. */
import type { AuthProps } from '../auth/AuthLayout';
import type { IncidentSummary } from '../incidents/types';
import type { MonitorPage, TeamSummary } from '../monitors/types';

/** One measured hourly mean; missing measurements remain null, including hours containing only absent work. */
export interface RegionalLatency {
    /** Mean observed response time in milliseconds, or null when none were measured. */
    meanMs: number | null;
    /** Number of non-null measured observations contributing to the mean. */
    samples: number;
}

/** Current data returned by the read-only dashboard service. */
export interface DashboardView {
    /** UTC instant at which the projection was generated. */
    generatedAt: string;
    /** Rolling 24-hour half-open UTC window. */
    window: { startsAt: string; endsAt: string };
    /** Disjoint operational states; only active monitors contribute up/down/unknown. */
    counts: {
        total: number;
        up: number;
        down: number;
        unknown: number;
        paused: number;
        suspended: number;
        openIncidents: number;
    };
    /** Stable bounded monitor page, with no network configuration. */
    monitors: MonitorPage;
    /** At most five unresolved incidents, newest first. */
    incidents: IncidentSummary[];
    /** Explicit same-team chart selection; null when there is no default row on this page. */
    selectedMonitor: { id: number; name: string } | null;
    /** Exactly 24 hourly buckets, preserving actual per-region measurements and gaps. */
    latency: { at: string; eu: RegionalLatency; us: RegionalLatency }[];
    /** Percentages from the future duration-weighted SLA service; currently all null rather than invented ratios. */
    uptime: {
        observedPercent: number | null;
        coveragePercent: number | null;
        slaPercent: number | null;
        series: { at: string; value: number | null }[];
    };
}

/** Inertia dashboard props; live reads replace only the dashboard root. */
export interface DashboardProps extends AuthProps {
    /** Authorized route identity. */
    team: TeamSummary;
    /** Operational projection and nullable metric contract. */
    dashboard: DashboardView;
    /** Names of malformed GET filters ignored by the server. */
    rejectedFilters: string[];
}

/** Build a stable dashboard URL without forwarding forged team, actor or unrecognized query fields. */
export function dashboardUrl(teamId: number, page = 1, monitorId?: number): string {
    const query = new URLSearchParams();
    if (page > 1) query.set('page', String(page));
    if (monitorId !== undefined) query.set('monitor', String(monitorId));
    const suffix = query.toString();
    return `/teams/${teamId}/dashboard${suffix === '' ? '' : `?${suffix}`}`;
}
