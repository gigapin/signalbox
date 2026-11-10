/** Describe private read-only metric/context props; all instants and bucket boundaries are UTC. */

/** Authoritative time-weighted availability, independent of a latency-region selection. */
export interface MonitorAvailabilityView {
    /** False when retained data cannot reconstruct the whole monitored part of the window. */
    available: boolean;
    /** Up / observed time, null without observations. */
    observedPercent: number | null;
    /** Observed / eligible time; unknown time reduces coverage. */
    coveragePercent: number | null;
    /** Null unless eligible time is fully observed and positive. */
    slaPercent: number | null;
    /** Seconds with fractional precision; null denotes unavailable history. */
    durations: {
        /** Agreed up time after exclusions. */
        upSeconds: number;
        /** Agreed down time after exclusions. */
        downSeconds: number;
        /** Eligible time without a valid verdict. */
        unknownSeconds: number;
        /** Pause, suspension and maintenance time. */
        excludedSeconds: number;
    } | null;
}

/** Half-open UTC window with its own honest duration categories. */
export interface MonitorAvailabilityBucket extends MonitorAvailabilityView {
    /** Inclusive requested start; durations are clipped to the monitor's lifetime. */
    startsAt: string;
    /** Exclusive requested end. */
    endsAt: string;
}

/** Exact raw observation statistics; zero milliseconds is a measured value. */
export interface MonitorLatencyStatistics {
    /** Arithmetic mean weighted by measured samples. */
    meanMs: number | null;
    /** Nearest-rank median, never an average of bucket percentiles. */
    p50Ms: number | null;
    /** Nearest-rank 95th percentile. */
    p95Ms: number | null;
    /** Number of measured observations, excluding unmeasured results. */
    samples: number;
}

/** Bounded read projection used by the future monitor cards and charts. */
export interface MonitorMetricsView {
    /** Projection generation time, distinct from an observation time. */
    generatedAt: string;
    /** Bucket boundaries are UTC; presentation may localize timestamps explicitly. */
    timezone: 'UTC';
    /** Current plan's maximum readable rollup history in days. */
    historyDays: number;
    /** Validated controls; region only filters latency observations. */
    selection: { period: '24h' | '7d' | '30d'; region: 'all' | 'eu' | 'us' };
    /** Whole-window availability from the existing SLA authority. */
    periods: Record<'24h' | '7d' | '30d', MonitorAvailabilityBucket>;
    /** Twenty-four rolling hour buckets, including missing/excluded time. */
    recent: MonitorAvailabilityBucket[];
    /** Up to thirty rolling day buckets or twenty-four hour buckets, with separate simulated regions. */
    latency: {
        /** False for unreadable retained history. */
        available: boolean;
        /** Regional statistics use raw observations because stored rollups combine regions. */
        source: 'raw';
        /** Exact whole-window regional statistics; null when the plan forbids this window. */
        summary: { eu: MonitorLatencyStatistics; us: MonitorLatencyStatistics } | null;
        /** Excluded regions have null statistics and zero samples; inspect selection when rendering. */
        series: {
            /** Inclusive UTC start. */
            startsAt: string;
            /** Exclusive UTC end. */
            endsAt: string;
            /** European observations. */
            eu: MonitorLatencyStatistics;
            /** US observations. */
            us: MonitorLatencyStatistics;
        }[];
    };
}

/** Contextual facts with no channel configuration or credentials. */
export interface MonitorContextView {
    /** Cached certificate expiry and retained current-revision observation provenance. */
    certificate: {
        /** Current means observed within two configured check intervals; stale does not mean expired. */
        state: 'not_applicable' | 'missing' | 'stale' | 'current';
        /** Cached verified expiry, null when missing or not applicable. */
        expiresAt: string | null;
        /** Matching retained observation, null when provenance is no longer available. */
        observedAt: string | null;
    };
    /** Effective override/default or null; target configuration alone never guarantees delivery. */
    notifications: {
        /** Effective policy identity. */
        id: number;
        /** Policy label. */
        name: string;
        /** Resolution source. */
        source: 'override' | 'default';
        /** Ordered escalation steps. */
        steps: {
            /** Delay from incident opening in minutes. */
            afterMinutes: number;
            /** Resolved team-local target labels and eligibility flags. */
            channels: {
                /** Channel identity. */
                id: number;
                /** User-defined label; no destination address or avatar. */
                name: string;
                /** Existing channel family. */
                type: string;
                /** Configured active flag. */
                isActive: boolean;
                /** Verification exists; quiet hours and delivery policy still apply. */
                isVerified: boolean;
                /** SMS remains a stub. */
                isStub: boolean;
            }[];
        }[];
    } | null;
}
