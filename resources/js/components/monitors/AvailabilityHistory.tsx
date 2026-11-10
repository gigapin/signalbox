/** Render recent duration bars with explicit gaps/exclusions and an equivalent keyboard-accessible data table. */
import Panel from '../ui/Panel';
import DataTable from '../ui/DataTable';
import type { MonitorAvailabilityBucket, MonitorMetricsView } from './metrics';
import { metricDuration } from './metricFormatting';

/** Server duration categories; presentation never derives a new uptime percentage. */
const CATEGORIES = [
    { key: 'upSeconds', label: 'Up', tone: 'up' },
    { key: 'downSeconds', label: 'Down', tone: 'down' },
    { key: 'unknownSeconds', label: 'Unknown', tone: 'unknown' },
    { key: 'excludedSeconds', label: 'Excluded', tone: 'excluded' },
] as const;

/** One visual duration segment within the requested hour. */
interface Segment {
    /** Textual meaning, also exposed in the table and chart label. */
    label: string;
    /** Classifier used only for colors/patterns. */
    tone: string;
    /** Aggregate time in seconds, never a continuous state claim. */
    seconds: number;
    /** Fraction of the requested bucket's duration, not an availability percentage. */
    percent: number;
}

/**
 * @param bucket Server-authoritative duration split and requested boundaries.
 * @returns Visual proportions including time outside the monitor's lifetime; null for unavailable/invalid input.
 */
function segments(bucket: MonitorAvailabilityBucket): Segment[] | null {
    const windowSeconds = (Date.parse(bucket.endsAt) - Date.parse(bucket.startsAt)) / 1000;
    const durations = bucket.durations;
    if (
        !bucket.available ||
        durations === null ||
        !Number.isFinite(windowSeconds) ||
        windowSeconds <= 0
    )
        return null;
    const values: Omit<Segment, 'percent'>[] = CATEGORIES.map(({ key, label, tone }) => ({
        label,
        tone,
        seconds: durations[key],
    }));
    if (values.some(({ seconds }) => !Number.isFinite(seconds) || seconds < 0)) return null;
    const total = values.reduce((sum, value) => sum + value.seconds, 0);
    if (total > windowSeconds + 0.000001) return null;
    values.push({
        label: 'Not monitored',
        tone: 'outside',
        seconds: Math.max(0, windowSeconds - total),
    });
    return values.map((value) => ({ ...value, percent: (value.seconds / windowSeconds) * 100 }));
}

/**
 * @param value ISO boundary supplied by the server.
 * @returns Calendar date and time explicitly formatted in UTC.
 */
function utc(value: string): string {
    return new Date(value).toLocaleString('en-GB', { timeZone: 'UTC' });
}

/** Metric history may be absent only on pre-existing browser-history entries. */
interface AvailabilityHistoryProps {
    /** Authorized recent buckets; never populated with invented samples. */
    metrics: MonitorMetricsView | undefined;
}

/**
 * @param props Current metric snapshot or an older entry without one.
 * @returns Static labelled bars, legend and native disclosure containing all numeric data.
 */
export default function AvailabilityHistory({ metrics }: AvailabilityHistoryProps) {
    const buckets = metrics?.recent ?? [];
    return (
        <Panel
            title="Last 24 hours history"
            description="Rolling hourly buckets in UTC. Each bar shows how time was classified; a partially observed hour is never shown as fully up."
        >
            {buckets.length === 0 ? (
                <p>Hourly history is unavailable in this snapshot.</p>
            ) : (
                <>
                    <ol className="ui-availability-bars" aria-label="Hourly availability">
                        {buckets.map((bucket) => {
                            const values = segments(bucket);
                            const label = `${utc(bucket.startsAt)} – ${utc(bucket.endsAt)} UTC: ${values === null ? 'Unavailable' : values.map((value) => `${value.label} ${metricDuration(value.seconds)}`).join(', ')}`;
                            return (
                                <li key={bucket.startsAt}>
                                    <div
                                        role="img"
                                        aria-label={label}
                                        className="ui-availability-bar"
                                        data-unavailable={values === null || undefined}
                                    >
                                        {values
                                            ?.filter((value) => value.seconds > 0)
                                            .map((value) => (
                                                <span
                                                    key={value.tone}
                                                    aria-hidden="true"
                                                    data-kind={value.tone}
                                                    style={{ height: `${value.percent}%` }}
                                                />
                                            ))}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                    <div className="ui-availability-boundaries ui-help">
                        <span>{utc(buckets[0]!.startsAt)} UTC</span>
                        <span>{utc(buckets[buckets.length - 1]!.endsAt)} UTC</span>
                    </div>
                    <ul className="ui-availability-legend" aria-label="Availability categories">
                        {[
                            ...CATEGORIES,
                            { label: 'Not monitored', tone: 'outside' },
                            { label: 'Unavailable', tone: 'unavailable' },
                        ].map(({ label, tone }) => (
                            <li key={tone}>
                                <span
                                    aria-hidden="true"
                                    className="ui-availability-key"
                                    data-kind={tone}
                                />
                                {label}
                            </li>
                        ))}
                    </ul>
                    <p className="ui-help">
                        Unknown is eligible time without a valid observation. Excluded covers
                        pauses, plan suspension and maintenance. Not monitored is outside this
                        monitor's lifetime. Unavailable means the period cannot be reconstructed.
                    </p>
                    <details>
                        <summary className="ui-link">View hourly availability data</summary>
                        <DataTable caption="Hourly availability durations, UTC">
                            <thead>
                                <tr>
                                    <th scope="col">Starts at (UTC)</th>
                                    <th scope="col">Ends at (UTC)</th>
                                    {CATEGORIES.map(({ label }) => (
                                        <th key={label} scope="col">
                                            {label}
                                        </th>
                                    ))}
                                    <th scope="col">Not monitored</th>
                                </tr>
                            </thead>
                            <tbody>
                                {buckets.map((bucket) => {
                                    const values = segments(bucket);
                                    return (
                                        <tr key={bucket.startsAt}>
                                            <th scope="row">
                                                <time dateTime={bucket.startsAt}>
                                                    {utc(bucket.startsAt)}
                                                </time>
                                            </th>
                                            <td>
                                                <time dateTime={bucket.endsAt}>
                                                    {utc(bucket.endsAt)}
                                                </time>
                                            </td>
                                            {[0, 1, 2, 3, 4].map((index) => (
                                                <td key={index}>
                                                    {metricDuration(
                                                        values?.[index]?.seconds ?? null,
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </DataTable>
                    </details>
                </>
            )}
        </Panel>
    );
}
