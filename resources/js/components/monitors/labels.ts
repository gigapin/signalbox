/** Map server enum values to English interface labels without duplicating the enum lists themselves. */

/** Human labels for monitor types; unknown values fall back to the raw value. */
const TYPE_LABELS: Record<string, string> = {
    http: 'HTTP',
    keyword: 'Keyword',
    tls: 'TLS certificate',
    dns: 'DNS',
    heartbeat: 'Heartbeat',
};

/** Human labels for observed statuses. */
const STATUS_LABELS: Record<string, string> = {
    up: 'Up',
    down: 'Down',
    unknown: 'Unknown',
};

/** Human labels for operational states. */
const STATE_LABELS: Record<string, string> = {
    active: 'Active',
    paused: 'Paused',
    suspended: 'Suspended by plan',
};

/** Human labels for simulated regions. */
const REGION_LABELS: Record<string, string> = {
    eu: 'Europe (simulated)',
    us: 'United States (simulated)',
};

/**
 * @param labels Known labels for one enum.
 * @param value Server value to display.
 * @returns Known label, or the raw value so new server cases stay visible.
 */
function label(labels: Record<string, string>, value: string): string {
    return labels[value] ?? value;
}

/**
 * @param value Monitor type value.
 * @returns Display label.
 */
export function typeLabel(value: string): string {
    return label(TYPE_LABELS, value);
}

/**
 * @param value Observed status value.
 * @returns Display label.
 */
export function statusLabel(value: string): string {
    return label(STATUS_LABELS, value);
}

/**
 * @param value Operational state value.
 * @returns Display label.
 */
export function stateLabel(value: string): string {
    return label(STATE_LABELS, value);
}

/**
 * @param value Region value.
 * @returns Display label.
 */
export function regionLabel(value: string): string {
    return label(REGION_LABELS, value);
}

/**
 * @param seconds Cadence in seconds.
 * @returns Compact human duration such as `5 min` or `30 s`.
 */
export function intervalLabel(seconds: number): string {
    if (seconds % 3600 === 0) {
        return `${seconds / 3600} h`;
    }

    if (seconds % 60 === 0) {
        return `${seconds / 60} min`;
    }

    return `${seconds} s`;
}
