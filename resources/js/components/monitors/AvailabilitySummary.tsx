/** Show time-weighted period cards directly from the authoritative availability projection. */
import { useId } from 'react';
import Panel from '../ui/Panel';
import type { MonitorAvailabilityBucket, MonitorMetricsView } from './metrics';
import { metricDuration, metricPercentage } from './metricFormatting';

/** One supported period and its authoritative nullable report. */
interface AvailabilityCardProps {
    /** Visible period label. */
    label: string;
    /** Report including its half-open UTC request window. */
    report: MonitorAvailabilityBucket;
}

/**
 * @param report Authoritative period and duration categories.
 * @returns Separate explanations for retention loss, unknown time, full exclusion and zero monitored life.
 */
function periodExplanation(report: MonitorAvailabilityBucket): string {
    if (!report.available || report.durations === null)
        return 'Retained data cannot reconstruct this period.';
    const { upSeconds, downSeconds, unknownSeconds, excludedSeconds } = report.durations;
    if (upSeconds + downSeconds + unknownSeconds + excludedSeconds === 0)
        return 'No monitored time in this period.';
    if (upSeconds + downSeconds + unknownSeconds === 0)
        return 'All monitored time was excluded; there is no eligible availability to measure.';
    if (report.observedPercent === null) return 'No observed availability in this period.';
    if (report.slaPercent === null)
        return 'SLA is unavailable while eligible time remains unknown.';
    return 'SLA is available because all eligible time was observed.';
}

/**
 * @param props Period label and its authoritative nullable report.
 * @returns A period card without recomputing percentages or averaging across monitors.
 */
function AvailabilityCard({ label, report }: AvailabilityCardProps) {
    const id = useId();
    const durations = report.available ? report.durations : null;
    return (
        <section aria-labelledby={id} className="ui-availability-card">
            <h3 id={id} className="ui-section-title">
                {label}
            </h3>
            <p className="ui-help">
                UTC window:{' '}
                <time dateTime={report.startsAt}>
                    {new Date(report.startsAt).toLocaleString('en-GB', { timeZone: 'UTC' })}
                </time>{' '}
                –{' '}
                <time dateTime={report.endsAt}>
                    {new Date(report.endsAt).toLocaleString('en-GB', { timeZone: 'UTC' })}
                </time>
            </p>
            <dl className="ui-monitor-facts">
                <div>
                    <dt>Observed uptime</dt>
                    <dd className="ui-metric-value">
                        {metricPercentage(report.available ? report.observedPercent : null)}
                    </dd>
                </div>
                <div>
                    <dt>Data coverage</dt>
                    <dd>{metricPercentage(report.available ? report.coveragePercent : null)}</dd>
                </div>
                <div>
                    <dt>SLA</dt>
                    <dd>{metricPercentage(report.available ? report.slaPercent : null)}</dd>
                </div>
                <div>
                    <dt>Time down</dt>
                    <dd>{metricDuration(durations?.downSeconds ?? null)}</dd>
                </div>
                <div>
                    <dt>Time unknown</dt>
                    <dd>{metricDuration(durations?.unknownSeconds ?? null)}</dd>
                </div>
                <div>
                    <dt>Time excluded</dt>
                    <dd>{metricDuration(durations?.excludedSeconds ?? null)}</dd>
                </div>
            </dl>
            <p className="ui-help">{periodExplanation(report)}</p>
        </section>
    );
}

/** Nullable only for an older Inertia history snapshot that predates metric props. */
interface AvailabilitySummaryProps {
    /** Current authorized metric snapshot, absent on historical entries only. */
    metrics: MonitorMetricsView | undefined;
}

/**
 * @param props Server-calculated availability periods, already restricted to life and retention.
 * @returns Three supported period cards or an honest unavailable-history notice.
 */
export default function AvailabilitySummary({ metrics }: AvailabilitySummaryProps) {
    return (
        <Panel
            title="Availability by period"
            description="Time-weighted availability for this monitor. Windows are clipped to its lifetime; pauses, suspension and maintenance are excluded. Downtime is a total for the window, not a continuous incident duration."
        >
            {metrics === undefined ? (
                <p>
                    Availability history is unavailable in this snapshot. Reload the page to request
                    current data.
                </p>
            ) : (
                <div className="ui-availability-periods">
                    <AvailabilityCard label="Last 24 hours" report={metrics.periods['24h']} />
                    <AvailabilityCard label="Last 7 days" report={metrics.periods['7d']} />
                    <AvailabilityCard label="Last 30 days" report={metrics.periods['30d']} />
                </div>
            )}
        </Panel>
    );
}
