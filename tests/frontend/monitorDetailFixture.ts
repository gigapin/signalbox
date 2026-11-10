/** Provide deterministic, redacted monitor detail and time-weighted history without customer or credential data. */
import type {
    MonitorMetricsView,
    MonitorAvailabilityBucket,
} from '../../resources/js/components/monitors/metrics';
import type { MonitorDetail } from '../../resources/js/components/monitors/types';

/** End of every requested rolling period, independent of the test machine's clock. */
const END = Date.parse('2026-11-10T12:00:00Z');

/**
 * @param startsAt Requested inclusive UTC start.
 * @param endsAt Requested exclusive UTC end.
 * @returns The same monitored lifetime across requested periods, including unknown/excluded time.
 */
function period(startsAt: string, endsAt: string): MonitorAvailabilityBucket {
    return {
        startsAt,
        endsAt,
        available: true,
        observedPercent: 98.8,
        coveragePercent: 94.38,
        slaPercent: null,
        durations: {
            upSeconds: 74700,
            downSeconds: 900,
            unknownSeconds: 4500,
            excludedSeconds: 4500,
        },
    };
}

/** Existing route identity and authorized safe detail; the monitor was created halfway into the first requested hour. */
export const detailMonitor: MonitorDetail = {
    id: 11,
    name: 'Checkout API',
    type: 'http',
    lastStatus: 'up',
    state: 'active',
    intervalSeconds: 300,
    regions: ['eu', 'us'],
    lastCheckedAt: '2026-11-10T11:59:00Z',
    nextCheckAt: '2026-11-10T12:04:00Z',
    timeoutMs: 5000,
    confirmationThreshold: 3,
    createdAt: '2026-11-09T12:30:00Z',
    withinQuota: true,
    configuration: {
        type: 'http',
        url: 'https://example.test/health?key=[REDACTED]',
        method: 'GET',
        header_names: ['authorization'],
        body: { present: false, bytes: 0 },
        expected_status: 200,
        follow_redirects: false,
    },
};

/** Actual duration categories for 24 rolling hours; period totals match the fixture's 23.5-hour monitored lifetime. */
export const detailMetrics: MonitorMetricsView = {
    generatedAt: new Date(END).toISOString(),
    timezone: 'UTC',
    historyDays: 90,
    selection: { period: '24h', region: 'all' },
    periods: {
        '24h': period(new Date(END - 86400000).toISOString(), new Date(END).toISOString()),
        '7d': period(new Date(END - 7 * 86400000).toISOString(), new Date(END).toISOString()),
        '30d': period(new Date(END - 30 * 86400000).toISOString(), new Date(END).toISOString()),
    },
    recent: Array.from({ length: 24 }, (_, index) => {
        const startsAt = new Date(END - (24 - index) * 3600000).toISOString();
        const endsAt = new Date(END - (23 - index) * 3600000).toISOString();
        let durations = { upSeconds: 3600, downSeconds: 0, unknownSeconds: 0, excludedSeconds: 0 };
        if (index === 0) durations = { ...durations, upSeconds: 1800 };
        if (index === 1)
            durations = {
                upSeconds: 900,
                downSeconds: 900,
                unknownSeconds: 900,
                excludedSeconds: 900,
            };
        if (index === 2)
            durations = { upSeconds: 0, downSeconds: 0, unknownSeconds: 3600, excludedSeconds: 0 };
        if (index === 3)
            durations = { upSeconds: 0, downSeconds: 0, unknownSeconds: 0, excludedSeconds: 3600 };
        return {
            startsAt,
            endsAt,
            available: true,
            observedPercent: null,
            coveragePercent: null,
            slaPercent: null,
            durations,
        };
    }),
    latency: { available: true, source: 'raw', summary: null, series: [] },
};

/** Complete private page props with read-only permissions; absent configuration is explicit and synthetic. */
export const detailProps = {
    team: { id: 7, name: 'Synthetic team' },
    auth: { user: { name: 'Viewer', email: 'viewer@example.test' } },
    realtime: null,
    monitorListUrl: '/teams/7/monitors?q=api&page=2',
    monitor: detailMonitor,
    metrics: detailMetrics,
    rejectedMetricFilters: [],
    recentIncidents: [],
    maintenance: { current: [], finished: [], reasonMaxLength: 1000 },
    escalation: { policyId: null, effective: null, options: [] },
    can: { update: false, delete: false, pause: false, resume: false },
};
