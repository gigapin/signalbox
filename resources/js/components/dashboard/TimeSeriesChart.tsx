/** Render bounded, accessible time series with real gaps, selectable series and an equivalent data table. */
import TableScrollArea from '../TableScrollArea';
import { useEffect, useId, useRef, useState } from 'react';

/** One comparable series in the same unit and UTC bucket axis. */
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
    /** Optional sample counts parallel to timestamps; zero stays distinct from a missing value. */
    samples?: number[];
    /** Optional exclusive UTC ends, for complete hour/day window labels. */
    endsAt?: string[];
    /** Detail charts nest below their enclosing h2; dashboard headings stay h2 by default. */
    headingLevel?: 2 | 3;
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
    samples,
    endsAt,
    headingLevel = 2,
}: ChartProps) {
    const container = useRef<HTMLElement>(null);
    const [chartWidth, setChartWidth] = useState(640);
    // Compensate for viewBox scaling so both axes retain a compact 12 CSS pixel font.
    const axisFontSize = (12 * 640) / chartWidth;

    useEffect(() => {
        if (!container.current || typeof ResizeObserver === 'undefined') return;

        const observer = new ResizeObserver(([entry]) => {
            const width = entry?.contentRect.width;
            if (width !== undefined && Number.isFinite(width) && width > 0) {
                setChartWidth(width);
            }
        });
        observer.observe(container.current);

        return () => observer.disconnect();
    }, []);

    const Heading = headingLevel === 3 ? 'h3' : 'h2';
    const [choice, setChoice] = useState(series[0]?.id);
    const tooltipId = useId();
    const [activePoint, setActivePoint] = useState<{ series: string; index: number } | null>(null);
    const selected = series.find((entry) => entry.id === choice) ?? series[0];
    const values = timestamps.map((_, index) => selected?.values[index] ?? null);
    const activeIndex = activePoint?.series === selected?.id ? activePoint?.index : undefined;
    const activeValue = activeIndex === undefined ? null : values[activeIndex];
    const activeTimestamp = activeIndex === undefined ? undefined : timestamps[activeIndex];
    const tooltipVisible = measured(activeValue, unit) && activeTimestamp !== undefined;
    const observations = values.filter((value) => measured(value, unit));
    const observedMaximum = Math.max(1, ...observations);
    const rawStep = observedMaximum / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const step =
        ([1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= rawStep) ?? 10) * magnitude;
    const maximum = unit === '%' ? 100 : step * 4;
    const ticks = Array.from({ length: 5 }, (_, index) => (maximum * index) / 4);
    const coordinates = values.map((value, index) =>
        measured(value, unit)
            ? {
                  x: 85 + (index * 530) / Math.max(1, timestamps.length - 1),
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
        <section ref={container} className="ui-panel" aria-label={title}>
            <Heading className="ui-section-title">{title}</Heading>
            <p className="ui-description">{description}</p>
            {series.length > 1 && (
                <div className="ui-actions mt-4" role="group" aria-label={`${title} series`}>
                    {series.map((entry) => (
                        <button
                            type="button"
                            key={entry.id}
                            aria-pressed={selected?.id === entry.id}
                            onClick={() => {
                                setChoice(entry.id);
                                setActivePoint(null);
                            }}
                            className="ui-button min-w-0 max-w-full wrap-anywhere"
                            data-variant={selected?.id === entry.id ? 'primary' : 'secondary'}
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
            )}
            {observations.length === 0 ? (
                <p className="my-8 text-sm text-ui-muted">{empty}</p>
            ) : (
                <div className="ui-chart-plot" onMouseLeave={() => setActivePoint(null)}>
                    <svg
                        role="img"
                        aria-label={`${title}: ${selected?.label ?? ''}`}
                        viewBox="0 0 640 200"
                        className="mt-4 w-full"
                    >
                        <title>{`${title}: ${selected?.label ?? ''}, UTC`}</title>
                        {ticks.map((tick) => {
                            const y = 165 - (tick * 150) / maximum;
                            return (
                                <g key={tick}>
                                    <line
                                        x1="85"
                                        y1={y}
                                        x2="615"
                                        y2={y}
                                        stroke="currentColor"
                                        className="text-ui-border"
                                        strokeOpacity="0.35"
                                    />
                                    <text
                                        x="75"
                                        y={y + axisFontSize / 3}
                                        textAnchor="end"
                                        fill="currentColor"
                                        fontSize={axisFontSize}
                                    >{`${Number(tick.toPrecision(6))} ${unit}`}</text>
                                </g>
                            );
                        })}
                        <path
                            d={path}
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            className="text-ui-accent"
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
                                        className="text-ui-accent"
                                        tabIndex={0}
                                        aria-label={`${selected?.label}: ${values[index]} ${unit}, ${label(timestamps[index] ?? '')} UTC`}
                                        aria-describedby={
                                            tooltipVisible && activeIndex === index
                                                ? tooltipId
                                                : undefined
                                        }
                                        stroke="transparent"
                                        strokeWidth="10"
                                        onMouseEnter={() =>
                                            setActivePoint({ series: selected?.id ?? '', index })
                                        }
                                        onFocus={() =>
                                            setActivePoint({ series: selected?.id ?? '', index })
                                        }
                                        onBlur={() => setActivePoint(null)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Escape') setActivePoint(null);
                                        }}
                                    />
                                ),
                        )}
                        {timestamps[0] && (
                            <text x="85" y="190" fill="currentColor" fontSize={axisFontSize}>
                                {label(timestamps[0])}
                            </text>
                        )}
                        {timestamps.at(-1) && (
                            <text
                                x="615"
                                y="190"
                                textAnchor="end"
                                fill="currentColor"
                                fontSize={axisFontSize}
                            >
                                {label(timestamps.at(-1) ?? '')}
                            </text>
                        )}
                    </svg>
                    {tooltipVisible && (
                        <div id={tooltipId} role="tooltip" className="ui-chart-tooltip">
                            <strong>{selected?.label}</strong>
                            <span>{`${activeValue} ${unit}`}</span>
                            <time
                                dateTime={activeTimestamp}
                            >{`${label(activeTimestamp)} UTC`}</time>
                            {activeIndex !== undefined && samples?.[activeIndex] !== undefined && (
                                <span>{`${samples[activeIndex]} measured samples`}</span>
                            )}
                        </div>
                    )}
                </div>
            )}
            <details className="mt-4 text-sm">
                <summary className="ui-link">View {title.toLowerCase()} data</summary>
                <TableScrollArea label={`${title} data viewport`}>
                    <table className="w-full text-left">
                        <caption className="sr-only">{`${title}, ${selected?.label ?? ''}, UTC`}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Bucket start (UTC)</th>
                                {endsAt && <th scope="col">Bucket end (UTC)</th>}
                                <th scope="col">{`Value (${unit})`}</th>
                                {samples && <th scope="col">Measured samples</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {timestamps.map((at, index) => (
                                <tr key={at}>
                                    <th scope="row" className="py-1 font-normal">
                                        <time dateTime={at}>{label(at)}</time>
                                    </th>
                                    {endsAt && (
                                        <td>
                                            {endsAt[index] === undefined ? (
                                                'Unavailable'
                                            ) : (
                                                <time dateTime={endsAt[index]}>
                                                    {label(endsAt[index])}
                                                </time>
                                            )}
                                        </td>
                                    )}
                                    <td>
                                        {measured(values[index], unit) ? values[index] : 'No data'}
                                    </td>
                                    {samples && <td>{samples[index] ?? 'Unavailable'}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableScrollArea>
            </details>
        </section>
    );
}
