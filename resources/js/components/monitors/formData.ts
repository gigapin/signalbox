/**
 * Convert between monitor projections, editable form state and the explicit patch sent to the server.
 * The server validates everything; these functions only decide which fields a submission includes.
 */
import type { MonitorDefaults, MonitorDetail } from './types';

/** One editable header row; rows with an empty name and value are dropped on submit. */
export interface HeaderRow {
    /** Header name as typed. */
    name: string;
    /** Header value as typed, preserved byte for byte. */
    value: string;
}

/** Editable form state; numeric inputs stay strings so empty and partial input reach validation unchanged. */
export interface MonitorFormData {
    /** Monitor label. */
    name: string;
    /** Selected monitor type. */
    type: string;
    /** Cadence in seconds. */
    interval_seconds: string;
    /** Timeout in milliseconds. */
    timeout_ms: string;
    /** Consecutive failure threshold. */
    confirmation_threshold: string;
    /** Selected simulated regions. */
    regions: string[];
    /** Full URL; never prefilled from the redacted projection. */
    url: string;
    /** HTTP method. */
    method: string;
    /** Header rows; never prefilled with values. */
    headers: HeaderRow[];
    /** Request body; empty means no body. */
    body: string;
    /** Expected HTTP status. */
    expected_status: string;
    /** Whether protected redirects are followed. */
    follow_redirects: boolean;
    /** Exact keyword. */
    expected_keyword: string;
    /** Keyword comparison mode. */
    keyword_mode: string;
    /** TLS certificate host. */
    tls_host: string;
    /** TLS port. */
    tls_port: string;
    /** Optional TLS expiry warning in days; empty sends null. */
    tls_expiry_warning_days: string;
    /** DNS query name. */
    dns_name: string;
    /** DNS record family. */
    dns_record_type: string;
    /** Expected DNS values, one per row. */
    dns_expected_values: string[];
    /** Optional resolver; empty sends null. */
    dns_resolver: string;
    /** Heartbeat tolerance in seconds. */
    heartbeat_grace_seconds: string;
    /** Edit only: replace the stored URL instead of keeping it. */
    replace_url: boolean;
    /** Edit only: replace the entire stored header set. */
    replace_headers: boolean;
    /** Edit only: replace or clear the stored body. */
    replace_body: boolean;
}

/** HTTP-family types that carry URL, headers and body secrets. */
export const HTTP_TYPES = ['http', 'keyword'];

/** Fields sent for every type. */
const COMMON_FIELDS = [
    'name',
    'type',
    'interval_seconds',
    'timeout_ms',
    'confirmation_threshold',
    'regions',
];

/** Non-secret fields sent per type; HTTP secrets are added separately. */
const TYPE_FIELDS: Record<string, string[]> = {
    http: ['method', 'expected_status', 'follow_redirects'],
    keyword: ['method', 'expected_status', 'follow_redirects', 'expected_keyword', 'keyword_mode'],
    tls: ['tls_host', 'tls_port', 'tls_expiry_warning_days'],
    dns: ['dns_name', 'dns_record_type', 'dns_expected_values', 'dns_resolver'],
    heartbeat: ['heartbeat_grace_seconds'],
};

/**
 * @param value Optional number from a projection.
 * @param fallback Value used when the projection has none.
 * @returns Input string.
 */
function text(value: number | null | undefined, fallback: number | string = ''): string {
    return value === null || value === undefined ? String(fallback) : String(value);
}

/**
 * @param defaults Server defaults for new monitors.
 * @returns Blank creation state with every secret field empty.
 */
export function initialFormData(defaults: MonitorDefaults): MonitorFormData {
    return {
        name: defaults.name,
        type: defaults.type,
        interval_seconds: text(defaults.interval_seconds),
        timeout_ms: text(defaults.timeout_ms),
        confirmation_threshold: text(defaults.confirmation_threshold),
        regions: [...defaults.regions],
        url: '',
        method: defaults.method,
        headers: [],
        body: '',
        expected_status: text(defaults.expected_status),
        follow_redirects: defaults.follow_redirects,
        expected_keyword: '',
        keyword_mode: defaults.keyword_mode,
        tls_host: '',
        tls_port: text(defaults.tls_port),
        tls_expiry_warning_days: '',
        dns_name: '',
        dns_record_type: defaults.dns_record_type,
        dns_expected_values: [''],
        dns_resolver: '',
        heartbeat_grace_seconds: text(defaults.heartbeat_grace_seconds),
        replace_url: true,
        replace_headers: true,
        replace_body: true,
    };
}

/**
 * Prefill only values the safe projection contains; URL, header values and body stay empty and are kept
 * on the server unless the user explicitly chooses to replace them.
 * @param monitor Safe detail projection.
 * @param defaults Server defaults used for fields of other types, in case the user changes type.
 * @returns Edit state with every replace flag off.
 */
export function editFormData(monitor: MonitorDetail, defaults: MonitorDefaults): MonitorFormData {
    const config = monitor.configuration;
    const blank = initialFormData(defaults);

    return {
        ...blank,
        name: monitor.name,
        type: monitor.type,
        interval_seconds: text(monitor.intervalSeconds),
        timeout_ms: text(monitor.timeoutMs),
        confirmation_threshold: text(monitor.confirmationThreshold),
        regions: [...monitor.regions],
        method: config.method ?? blank.method,
        expected_status: text(config.expected_status, blank.expected_status),
        follow_redirects: config.follow_redirects ?? blank.follow_redirects,
        expected_keyword: config.expected_keyword ?? '',
        keyword_mode: config.keyword_mode ?? blank.keyword_mode,
        tls_host: config.tls_host ?? '',
        tls_port: text(config.tls_port, blank.tls_port),
        tls_expiry_warning_days: text(config.tls_expiry_warning_days),
        dns_name: config.dns_name ?? '',
        dns_record_type: config.dns_record_type ?? blank.dns_record_type,
        dns_expected_values:
            config.dns_expected_values && config.dns_expected_values.length > 0
                ? [...config.dns_expected_values]
                : [''],
        dns_resolver: config.dns_resolver ?? '',
        heartbeat_grace_seconds: text(
            config.heartbeat_grace_seconds,
            blank.heartbeat_grace_seconds,
        ),
        replace_url: false,
        replace_headers: false,
        replace_body: false,
    };
}

/**
 * Secrets must be supplied when creating, or when the type changes, because the server discards the
 * previous type-specific configuration and requires the new one completely.
 * @param data Current form state.
 * @param originalType Persisted type in edit mode, or null when creating.
 * @returns Whether URL, headers and body are always sent.
 */
export function secretsRequired(data: MonitorFormData, originalType: string | null): boolean {
    return originalType === null || data.type !== originalType;
}

/**
 * @param data Current form state.
 * @param originalType Persisted type in edit mode, or null when creating.
 * @returns Explicit patch containing only fields relevant to the selected type; unreplaced secrets are omitted.
 */
export function buildPayload(
    data: MonitorFormData,
    originalType: string | null,
): Record<string, unknown> {
    const payload: Record<string, unknown> = {};
    const fields = [...COMMON_FIELDS, ...(TYPE_FIELDS[data.type] ?? [])];

    for (const field of fields) {
        payload[field] = data[field as keyof MonitorFormData];
    }

    if (data.type === 'tls') {
        payload.tls_expiry_warning_days =
            data.tls_expiry_warning_days === '' ? null : data.tls_expiry_warning_days;
    }

    if (data.type === 'dns') {
        payload.dns_resolver = data.dns_resolver === '' ? null : data.dns_resolver;
    }

    if (HTTP_TYPES.includes(data.type)) {
        const required = secretsRequired(data, originalType);

        if (required || data.replace_url) {
            payload.url = data.url;
        }

        if (required || data.replace_headers) {
            payload.headers = Object.fromEntries(
                data.headers
                    .filter((row) => row.name !== '' || row.value !== '')
                    .map((row) => [row.name, row.value]),
            );
        }

        if (required || data.replace_body) {
            payload.body = data.body === '' ? null : data.body;
        }
    }

    return payload;
}
