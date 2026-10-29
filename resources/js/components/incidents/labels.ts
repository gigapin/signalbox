/** Map incident enum values and durations to English interface labels. */

/** Human labels for incident statuses; unknown values fall back to the raw value. */
const STATUS_LABELS: Record<string, string> = {
    investigating: 'Investigating',
    identified: 'Identified',
    monitoring: 'Monitoring',
    resolved: 'Resolved',
};

/** Human labels for severities. */
const SEVERITY_LABELS: Record<string, string> = {
    critical: 'Critical',
    major: 'Major',
    minor: 'Minor',
};

/** Human labels for list states. */
const STATE_LABELS: Record<string, string> = {
    open: 'Open',
    resolved: 'Resolved',
};

/** Human labels for maintenance phases. */
const PHASE_LABELS: Record<string, string> = {
    upcoming: 'Upcoming',
    ongoing: 'In progress',
    finished: 'Finished',
};

/**
 * @param value Incident status value.
 * @returns Display label.
 */
export function incidentStatusLabel(value: string): string {
    return STATUS_LABELS[value] ?? value;
}

/**
 * @param value Severity value.
 * @returns Display label.
 */
export function severityLabel(value: string): string {
    return SEVERITY_LABELS[value] ?? value;
}

/**
 * @param value List state value.
 * @returns Display label.
 */
export function incidentStateLabel(value: string): string {
    return STATE_LABELS[value] ?? value;
}

/**
 * @param value Maintenance phase value.
 * @returns Display label.
 */
export function phaseLabel(value: string): string {
    return PHASE_LABELS[value] ?? value;
}

/**
 * @param value Stable check error value, such as `connection_failed`.
 * @returns Readable sentence-case text, such as `Connection failed`.
 */
export function causeLabel(value: string): string {
    const words = value.replaceAll('_', ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * @param seconds Non-negative duration in seconds.
 * @returns Compact duration such as `2 h 5 min`, `4 min 10 s` or `0 s`.
 */
export function durationLabel(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    if (hours > 0) {
        return minutes > 0 ? `${hours} h ${minutes} min` : `${hours} h`;
    }

    if (minutes > 0) {
        return rest > 0 ? `${minutes} min ${rest} s` : `${minutes} min`;
    }

    return `${rest} s`;
}
