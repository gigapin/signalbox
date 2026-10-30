/** Render bounded, accessible time series with real gaps, selectable series and an equivalent data table. */
import { useState } from 'react';

/** One comparable series in the same unit and hourly time axis. */
export interface ChartSeries {
    /** Stable series identity used by the keyboard-operable selection controls. */
    id: string;
    /** Human-readable region or metric label. */
    label: string;
    /** Values parallel to the timestamps; null/absent/non-finite values represent gaps. */
    values: (number | null)[];
}

/** Inputs for a read-only chart; no domain metric is calculated here. */
interface ChartProps {
    /** Visible heading and accessible graphic name. */
    title: string;
    /** Explicit meaning, aggregation and window information. */
    description: string;
    /** Canonical UTC bucket starts, in chronological order. */
    timestamps: string[];
    /** Comparable series offered by this chart. */
    series: ChartSeries[];
    /** Axis/table unit, milliseconds or percentages. */
    unit: 'ms' | '%';
    /** Truthful empty-state message, without substituting zero or 100%. */
    empty: string;
}

/** Format a UTC bucket label independently of the browser's local time zone. */
function label(at: string): string {
    return new Intl.DateTimeFormat('en', {
        timeZone: 'UTC',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date(at));
}

/** Keep invalid chart values as gaps; percentage bounds prevent misleading axes for future service output. */
function measured(value: number | null | undefined, unit: 'ms' | '%'): value is number {
    return (
        typeof value === 'number' &&
        Number.isFinite(value) &&
        value >= 0 &&
        (unit !== '%' || value <= 100)
    );
}

/** Show a responsive SVG, preserving null gaps and single observations instead of interpolating missing data. */
export default function TimeSeriesChart({
    title,
    description,
    timestamps,
    series,
    unit,
    empty,
}: ChartProps) {
    const [choice, setChoice] = useState(series[0]?.id);
    const selected = series.find((entry) => entry.id === choice) ?? series[0];
    const values = timestamps.map((_, index) => selected?.values[index] ?? null);
    const observations = values.filter((value) => measured(value, unit));
    const maximum = unit === '%' ? 100 : Math.max(1, ...observations);
    const coordinates = values.map((value, index) =>
        measured(value, unit)
            ? {
                  x: 45 + (index * 570) / Math.max(1, timestamps.length - 1),
                  y: 165 - (value * 150) / maximum,
              }
            : null,
    );
    const path = coordinates
        .map((point, index) => {
            if (point === null) return '';
            const command = index === 0 || coordinates[index - 1] === null ? 'M' : 'L';
            return `${command}${point.x.toFixed(2)},${point.y.toFixed(2)}`;
        })
        .join(' ');

    return (
        <section className="min-w-0 rounded border border-slate-700 p-5" aria-label={title}>
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="mt-2 text-sm text-slate-400">{description}</p>
            {series.length > 1 && (
                <div className="mt-4 flex gap-3" role="group" aria-label={`${title} series`}>
                    {series.map((entry) => (
                        <button
                            type="button"
                            key={entry.id}
                            aria-pressed={selected?.id === entry.id}
                            onClick={() => setChoice(entry.id)}
                            className="rounded border border-slate-600 px-3 py-1 aria-pressed:border-emerald-400 aria-pressed:text-emerald-300"
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
            )}
            {observations.length === 0 ? (
                <p className="my-8 text-sm text-slate-300">{empty}</p>
            ) : (
                <svg
                    role="img"
                    aria-label={`${title}: ${selected?.label ?? ''}`}
                    viewBox="0 0 640 200"
                    className="mt-4 w-full"
                >
                    <title>{`${title}: ${selected?.label ?? ''}, UTC`}</title>
                    <line
                        x1="45"
                        y1="165"
                        x2="615"
                        y2="165"
                        stroke="currentColor"
                        className="text-slate-600"
                    />
                    <text
                        x="2"
                        y="20"
                        fill="currentColor"
                        fontSize="11"
                    >{`${Number(maximum.toFixed(2))} ${unit}`}</text>
                    <text x="12" y="165" fill="currentColor" fontSize="11">
                        0
                    </text>
                    <path
                        d={path}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        className="text-emerald-400"
                    />
                    {coordinates.map(
                        (point, index) =>
                            point !== null && (
                                <circle
                                    key={index}
                                    cx={point.x}
                                    cy={point.y}
                                    r="2.5"
                                    fill="currentColor"
                                    className="text-emerald-300"
                                />
                            ),
                    )}
                    {timestamps[0] && (
                        <text x="45" y="190" fill="currentColor" fontSize="11">
                            {label(timestamps[0])}
                        </text>
                    )}
                    {timestamps.at(-1) && (
                        <text x="615" y="190" textAnchor="end" fill="currentColor" fontSize="11">
                            {label(timestamps.at(-1) ?? '')}
                        </text>
                    )}
                </svg>
            )}
            <details className="mt-4 text-sm">
                <summary className="cursor-pointer text-emerald-300">
                    View {title.toLowerCase()} data
                </summary>
                <div className="mt-3 max-h-64 overflow-auto">
                    <table className="w-full text-left">
                        <caption className="sr-only">{`${title}, ${selected?.label ?? ''}, UTC`}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Bucket start (UTC)</th>
                                <th scope="col">{`Value (${unit})`}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {timestamps.map((at, index) => (
                                <tr key={at}>
                                    <th scope="row" className="py-1 font-normal">
                                        <time dateTime={at}>{label(at)}</time>
                                    </th>
                                    <td>
                                        {measured(values[index], unit) ? values[index] : 'No data'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    );
}
