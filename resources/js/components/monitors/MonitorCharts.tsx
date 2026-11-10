/** Present exact regional latency and agreed availability with bounded GET controls and equivalent data. */
import { Link } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { useReadNavigation } from '../../hooks/useReadNavigation';
import Panel from '../ui/Panel';
import Alert from '../ui/Alert';
import TimeSeriesChart from '../dashboard/TimeSeriesChart';
import DataTable from '../ui/DataTable';
import type { MonitorMetricsView } from './metrics';
import { monitorMetricsUrl } from './urls';

/** Typed display labels for the server's bounded vocabulary. */
const PERIODS = [
    { value: '24h', label: '24 hours' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
] as const;
/** Existing simulated regions; all exposes separate EU/US charts rather than a mean of regional statistics. */
const REGIONS = [
    { value: 'all', label: 'All regions' },
    { value: 'eu', label: 'EU' },
    { value: 'us', label: 'US' },
] as const;

/** Explicit resource and authorized projection, with nullable compatibility for old history. */
interface MonitorChartsProps {
    /** Route team for every GET control. */
    teamId: number;
    /** Route-bound monitor. */
    monitorId: number;
    /** Server-normalized monitor-list return URL. */
    returnTo: string;
    /** Current selection and computed values; absent on older history only. */
    metrics: MonitorMetricsView | undefined;
    /** Names only of malformed metric query parameters. */
    rejected: string[];
}

/**
 * @param props Safe resource context, current projection and rejected filter names.
 * @returns Period/region controls, exact regional statistics and chart/table alternatives without new calculations.
 */
export default function MonitorCharts({
    teamId,
    monitorId,
    returnTo,
    metrics,
    rejected,
}: MonitorChartsProps) {
    const read = useReadNavigation();
    const supported = metrics?.supportedPeriods;
    const uptime = metrics?.uptime;
    if (!metrics || !supported || !uptime)
        return (
            <Panel title="Monitor charts">
                <p>
                    Charts are unavailable in this snapshot. Reload the page to request current
                    data.
                </p>
            </Panel>
        );
    const { period, region } = metrics.selection;
    const regions = region === 'all' ? (['eu', 'us'] as const) : [region];
    const label = PERIODS.find((entry) => entry.value === period)?.label ?? period;

    /** Resume only an ordinary same-tab GET after the shared draft guard has approved the original link. */
    function follow(event: MouseEvent, href: string): void {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)
            return;
        event.preventDefault();
        read.navigate(href, {}, { preserveScroll: true });
    }

    return (
        <Panel
            title="Monitor charts"
            description={`Last ${label} · ${period === '24h' ? 'Hourly' : 'Rolling 24-hour'} buckets · UTC. Regions are simulated; region selection applies only to measured latency.`}
        >
            <nav aria-label="Chart period" className="ui-actions">
                {PERIODS.map((entry) => {
                    const href = monitorMetricsUrl(
                        teamId,
                        monitorId,
                        entry.value,
                        region,
                        returnTo,
                    );
                    return supported.includes(entry.value) ? (
                        <Link
                            key={entry.value}
                            href={href}
                            className="ui-button"
                            data-variant={period === entry.value ? 'primary' : 'secondary'}
                            aria-current={period === entry.value ? 'true' : undefined}
                            aria-disabled={read.loading || undefined}
                            onClick={(event) => follow(event, href)}
                        >
                            {entry.label}
                        </Link>
                    ) : (
                        <span key={entry.value} className="ui-help">
                            {entry.label} unavailable for this history
                        </span>
                    );
                })}
            </nav>
            <nav aria-label="Latency region" className="ui-actions mt-4">
                {REGIONS.map((entry) => {
                    const href = monitorMetricsUrl(
                        teamId,
                        monitorId,
                        period,
                        entry.value,
                        returnTo,
                    );
                    return (
                        <Link
                            key={entry.value}
                            href={href}
                            className="ui-button"
                            data-variant={region === entry.value ? 'primary' : 'secondary'}
                            aria-current={region === entry.value ? 'true' : undefined}
                            aria-disabled={read.loading || undefined}
                            onClick={(event) => follow(event, href)}
                        >
                            {entry.label}
                        </Link>
                    );
                })}
            </nav>
            {read.loading && (
                <p role="status" className="ui-help">
                    Loading chart selection… Previous charts are still shown.
                </p>
            )}
            {read.failed && (
                <Alert
                    title="Chart selection could not be loaded"
                    tone="danger"
                    announce="assertive"
                >
                    <p>
                        Previous charts are still shown. Choose a period or region again to retry.
                    </p>
                </Alert>
            )}
            {rejected.length > 0 && (
                <Alert title="Invalid chart filters ignored" tone="warning">
                    <p>{rejected.join(', ')}</p>
                </Alert>
            )}
            <div className="ui-monitor-detail-stack mt-4" aria-busy={read.loading}>
                {metrics.latency.available ? (
                    <>
                        <p className="ui-help">
                            Only measured observations contribute to latency. Zero milliseconds is a
                            measurement; missing values stay gaps. Means and percentiles come from
                            the complete observation distribution, not averages of bucket
                            statistics.
                        </p>
                        {regions.map((key) => (
                            <TimeSeriesChart
                                key={key}
                                title={`Response time ${key.toUpperCase()}`}
                                headingLevel={3}
                                description="Mean and nearest-rank p50/p95 in milliseconds, including measured failures."
                                timestamps={metrics.latency.series.map((point) => point.startsAt)}
                                endsAt={metrics.latency.series.map((point) => point.endsAt)}
                                samples={metrics.latency.series.map((point) => point[key].samples)}
                                series={[
                                    {
                                        id: 'mean',
                                        label: 'Mean',
                                        values: metrics.latency.series.map(
                                            (point) => point[key].meanMs,
                                        ),
                                    },
                                    {
                                        id: 'p50',
                                        label: 'p50',
                                        values: metrics.latency.series.map(
                                            (point) => point[key].p50Ms,
                                        ),
                                    },
                                    {
                                        id: 'p95',
                                        label: 'p95',
                                        values: metrics.latency.series.map(
                                            (point) => point[key].p95Ms,
                                        ),
                                    },
                                ]}
                                unit="ms"
                                empty="No measured response times in this region and window."
                            />
                        ))}
                        <DataTable caption="Whole-window latency statistics">
                            <thead>
                                <tr>
                                    <th scope="col">Region</th>
                                    <th scope="col">Mean (ms)</th>
                                    <th scope="col">p50 (ms)</th>
                                    <th scope="col">p95 (ms)</th>
                                    <th scope="col">Measured samples</th>
                                </tr>
                            </thead>
                            <tbody>
                                {regions.map((key) => {
                                    const summary = metrics.latency.summary?.[key];
                                    return (
                                        <tr key={key}>
                                            <th scope="row">{key.toUpperCase()}</th>
                                            <td>{summary?.meanMs ?? 'No data'}</td>
                                            <td>{summary?.p50Ms ?? 'No data'}</td>
                                            <td>{summary?.p95Ms ?? 'No data'}</td>
                                            <td>{summary?.samples ?? 'Unavailable'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </DataTable>
                    </>
                ) : (
                    <p>Response time history is unavailable for this window.</p>
                )}
                {uptime.available ? (
                    <TimeSeriesChart
                        title="Availability over time"
                        headingLevel={3}
                        description="Agreed time-weighted availability across regions. Observed uptime excludes unknown time; check Data coverage to distinguish gaps. SLA requires fully observed eligible time. Region selection does not change these values."
                        timestamps={uptime.series.map((point) => point.startsAt)}
                        endsAt={uptime.series.map((point) => point.endsAt)}
                        series={[
                            {
                                id: 'observed',
                                label: 'Observed uptime',
                                values: uptime.series.map((point) =>
                                    point.available ? point.observedPercent : null,
                                ),
                            },
                            {
                                id: 'coverage',
                                label: 'Data coverage',
                                values: uptime.series.map((point) =>
                                    point.available ? point.coveragePercent : null,
                                ),
                            },
                            {
                                id: 'sla',
                                label: 'SLA',
                                values: uptime.series.map((point) =>
                                    point.available ? point.slaPercent : null,
                                ),
                            },
                        ]}
                        unit="%"
                        empty="No observed availability for this series and window."
                    />
                ) : (
                    <p>
                        Availability chart history is unavailable for this window. Period totals may
                        still be available from retained aggregates.
                    </p>
                )}
            </div>
        </Panel>
    );
}
