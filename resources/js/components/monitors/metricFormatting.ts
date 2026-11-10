/** Format server-calculated availability without turning absent or invalid values into numeric success. */

/**
 * @param value Authoritative percentage, already conservatively rounded by the server.
 * @returns Two-decimal percentage where safe; preserve input precision if rounding would overstate it.
 */
export function metricPercentage(value: number | null): string {
    if (value === null || !Number.isFinite(value) || value < 0 || value > 100) return 'Unavailable';
    const formatted = value.toFixed(2);
    // Preserve a more precise input rather than rounding a partial observation to full success.
    return `${Number(formatted) > value ? value : formatted}%`;
}

/**
 * @param seconds Aggregate duration in seconds, retaining up to six fractional digits.
 * @returns Human duration with units, never a claim of continuously being up or down.
 */
export function metricDuration(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return 'Unavailable';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = Number((seconds % 60).toFixed(6));
    const parts = [];
    if (days > 0) parts.push(`${days} d`);
    if (days > 0 || hours > 0) parts.push(`${hours} h`);
    if (days > 0 || hours > 0 || minutes > 0) parts.push(`${minutes} min`);
    parts.push(`${rest} s`);
    return parts.join(' ');
}
