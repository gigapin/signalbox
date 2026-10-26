/** Describe the monitor page projections sent by MonitorPageController and MonitorPresenter. */
import type { AuthProps } from '../auth/AuthLayout';

/** Minimal identity of the route team. */
export interface TeamSummary {
    /** Route team identifier used to build page and mutation URLs. */
    id: number;
    /** Human-readable team name. */
    name: string;
}

/** One list row; contains no configuration values. */
export interface MonitorSummary {
    /** Team-local monitor identifier. */
    id: number;
    /** Human-readable label. */
    name: string;
    /** Configuration variant value, such as `http`. */
    type: string;
    /** Latest observation: `up`, `down` or `unknown`. */
    lastStatus: string;
    /** Scheduling eligibility: `active`, `paused` or `suspended` (suspension takes precedence). */
    state: string;
    /** Configured cadence in seconds. */
    intervalSeconds: number;
    /** Canonical simulated regions. */
    regions: string[];
    /** ISO-8601 UTC instant of the latest observation, or null before the first check. */
    lastCheckedAt: string | null;
}

/**
 * Safe configuration projection keyed by input field names. HTTP variants expose a redacted URL,
 * header names and body size only; other variants contain no secrets.
 */
export interface MonitorConfigurationView {
    /** Configuration variant value. */
    type: string;
    /** Redacted URL for HTTP and keyword monitors. */
    url?: string;
    /** HTTP method for HTTP and keyword monitors. */
    method?: string;
    /** Sorted lowercase header names; values are never sent. */
    header_names?: string[];
    /** Whether a body is configured and its size in bytes. */
    body?: { present: boolean; bytes: number };
    /** Expected HTTP response status. */
    expected_status?: number;
    /** Whether protected redirects are followed. */
    follow_redirects?: boolean;
    /** Exact keyword for keyword monitors. */
    expected_keyword?: string;
    /** `present` or `absent`. */
    keyword_mode?: string;
    /** Certificate hostname for TLS monitors. */
    tls_host?: string;
    /** TCP port for TLS monitors. */
    tls_port?: number;
    /** Optional expiry warning window in days. */
    tls_expiry_warning_days?: number | null;
    /** DNS query name. */
    dns_name?: string;
    /** DNS record family. */
    dns_record_type?: string;
    /** Expected DNS record values. */
    dns_expected_values?: string[];
    /** Optional literal resolver address. */
    dns_resolver?: string | null;
    /** Heartbeat tolerance in seconds. */
    heartbeat_grace_seconds?: number;
}

/** Detail projection used by the show and edit pages. */
export interface MonitorDetail extends MonitorSummary {
    /** Execution budget in milliseconds. */
    timeoutMs: number;
    /** Consecutive regional failures required before an incident. */
    confirmationThreshold: number;
    /** ISO-8601 UTC next scheduled slot, or null while not yet scheduled. */
    nextCheckAt: string | null;
    /** ISO-8601 UTC creation instant. */
    createdAt: string | null;
    /** Whether the monitor's deterministic position fits the current plan quota. */
    withinQuota: boolean;
    /** Safe type-specific settings. */
    configuration: MonitorConfigurationView;
    /** Non-secret heartbeat metadata, present only for heartbeat monitors. */
    heartbeat?: HeartbeatView;
}

/** Safe arming and receipt metadata without bearer credentials or their digests. */
export interface HeartbeatView {
    /** Whether a dedicated credential has been issued and remains valid. */
    configured: boolean;
    /** Absolute public ingress URL without secrets. */
    endpoint: string;
    /** Current arming generation's UTC start. */
    startedAt: string;
    /** Last accepted UTC receipt, or null before the first call. */
    lastReceivedAt: string | null;
    /** Inclusive rolling deadline; paused monitors are rearmed on explicit resume. */
    dueAt: string;
}

/** One page of list rows with counters; links are built client-side from the active filters. */
export interface MonitorPage {
    /** Rows of the current page. */
    data: MonitorSummary[];
    /** One-based current page. */
    currentPage: number;
    /** Last available page, at least one. */
    lastPage: number;
    /** Rows per page. */
    perPage: number;
    /** Matching rows across all pages. */
    total: number;
    /** One-based index of the first row shown, or null on an empty page. */
    from: number | null;
    /** One-based index of the last row shown, or null on an empty page. */
    to: number | null;
}

/** Accepted list filters echoed by the server; null means "any". */
export interface MonitorFilters {
    /** Free-text search over name, TLS host and DNS name. */
    q: string;
    /** Exact monitor type. */
    type: string | null;
    /** Exact observed status. */
    status: string | null;
    /** Exact operational state. */
    state: string | null;
}

/** Informational plan quota; the server still enforces limits on every mutation. */
export interface MonitorUsage {
    /** Monitors held, including paused and suspended ones. */
    used: number;
    /** Plan maximum. */
    limit: number;
    /** Fastest allowed cadence in seconds. */
    minimumIntervalSeconds: number;
    /** Whether another creation would currently be refused. */
    full: boolean;
}

/** Enum values offered by filters and forms, supplied by the server. */
export interface MonitorOptions {
    /** Monitor types. */
    types: string[];
    /** Observed statuses. */
    statuses: string[];
    /** Operational states. */
    states: string[];
    /** Simulated regions. */
    regions: string[];
    /** HTTP methods. */
    methods: string[];
    /** Keyword comparison modes. */
    keywordModes: string[];
    /** DNS record families. */
    dnsRecordTypes: string[];
}

/** Starting form values keyed by input field name. */
export interface MonitorDefaults {
    /** Empty label. */
    name: string;
    /** Initially selected type. */
    type: string;
    /** Suggested cadence, already raised to the plan minimum. */
    interval_seconds: number;
    /** Suggested timeout in milliseconds. */
    timeout_ms: number;
    /** Suggested confirmation threshold. */
    confirmation_threshold: number;
    /** Initially selected regions. */
    regions: string[];
    /** Initial HTTP method. */
    method: string;
    /** Initial expected status. */
    expected_status: number;
    /** Initial redirect setting. */
    follow_redirects: boolean;
    /** Initial keyword mode. */
    keyword_mode: string;
    /** Initial TLS port. */
    tls_port: number;
    /** Initial DNS record family. */
    dns_record_type: string;
    /** Initial heartbeat tolerance in seconds. */
    heartbeat_grace_seconds: number;
}

/** Props shared by every monitor page. */
export interface MonitorPageProps extends AuthProps {
    /** Route team. */
    team: TeamSummary;
}
