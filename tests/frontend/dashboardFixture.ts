/** Share deterministic public-only dashboard data between page and native Echo acceptance tests. */
import type { DashboardView } from '../../resources/js/components/dashboard/types';

/** Three hourly UTC buckets, including a genuine missing interval. */
export const timestamps = [
    '2026-10-30T09:00:00+00:00',
    '2026-10-30T10:00:00+00:00',
    '2026-10-30T11:00:00+00:00',
];
/** Exact initial projection contract, with no made-up availability values. */
export const dashboard: DashboardView = {
    generatedAt: '2026-10-30T12:00:00+00:00',
    window: { startsAt: '2026-10-29T12:00:00+00:00', endsAt: '2026-10-30T12:00:00+00:00' },
    counts: { total: 1, up: 1, down: 0, unknown: 0, paused: 0, suspended: 0, openIncidents: 0 },
    monitors: {
        data: [
            {
                id: 11,
                name: 'Checkout API',
                type: 'http',
                lastStatus: 'up',
                state: 'active',
                intervalSeconds: 300,
                regions: ['eu', 'us'],
                lastCheckedAt: null,
            },
        ],
        currentPage: 1,
        lastPage: 1,
        perPage: 20,
        total: 1,
        from: 1,
        to: 1,
    },
    incidents: [],
    selectedMonitor: { id: 11, name: 'Checkout API' },
    latency: timestamps.map((at, index) => ({
        at,
        eu: { meanMs: index === 1 ? null : index * 100, samples: index === 1 ? 0 : 1 },
        us: { meanMs: 400, samples: 1 },
    })),
    uptime: {
        observedPercent: null,
        coveragePercent: null,
        slaPercent: null,
        series: timestamps.map((at) => ({ at, value: null })),
    },
};
/** Current route identity and actor metadata used by authoritative recovery checks. */
export const identity = {
    team: { id: 7, name: 'Synthetic team' },
    auth: { user: { name: 'Actor', email: 'actor@example.test' } },
};
