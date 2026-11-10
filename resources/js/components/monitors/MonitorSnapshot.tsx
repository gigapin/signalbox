/** Separate a monitor's latest observation, operational state, maintenance and configured timing. */
import type { MonitorConfigurationView, MonitorDetail } from './types';
import type { MaintenanceView } from '../incidents/types';
import Panel from '../ui/Panel';
import Badge from '../ui/Badge';
import { StateBadge, StatusBadge } from './MonitorBadges';
import { intervalLabel, regionLabel } from './labels';

/**
 * @param configuration Server-redacted configuration only; never raw transport settings.
 * @returns Safe destination text without an outbound link or invented hostname.
 */
export function monitorDestination(configuration: MonitorConfigurationView): string {
    if (configuration.type === 'http' || configuration.type === 'keyword')
        return configuration.url ?? 'Destination unavailable';
    if (configuration.type === 'tls') {
        if (!configuration.tls_host) return 'Destination unavailable';
        return configuration.tls_port === undefined
            ? configuration.tls_host
            : `${configuration.tls_host}:${configuration.tls_port}`;
    }
    if (configuration.type === 'dns') return configuration.dns_name ?? 'Destination unavailable';
    if (configuration.type === 'heartbeat') return 'Receives heartbeat signals';
    return 'Destination unavailable';
}

/** Current authorized observation and existing server-classified maintenance windows. */
interface MonitorSnapshotProps {
    /** Safe monitor detail, with nullable observation/scheduling instants. */
    monitor: MonitorDetail;
    /** Ongoing phase comes from the same authorized server snapshot, not a browser-clock inference. */
    maintenance: MaintenanceView;
}

/**
 * @param value ISO instant, or null when no observation/schedule exists.
 * @param empty Explanation for an absent instant.
 * @returns A local time element or the caller's explicit empty label.
 */
function localInstant(value: string | null, empty: string) {
    return value === null ? (
        empty
    ) : (
        <time dateTime={value}>{new Date(value).toLocaleString()}</time>
    );
}

/**
 * @param props Monitor and maintenance facts; current activity never overwrites the last observed result.
 * @returns Status and timing cards, with no inferred continuous uptime duration.
 */
export default function MonitorSnapshot({ monitor, maintenance }: MonitorSnapshotProps) {
    const ongoing = maintenance.current.some((window) => window.phase === 'ongoing');
    return (
        <div className="ui-monitor-snapshot">
            <Panel title="Monitor status">
                <dl className="ui-monitor-facts">
                    <div>
                        <dt>Last observed status</dt>
                        <dd>
                            <StatusBadge
                                status={
                                    monitor.lastCheckedAt === null ? 'unknown' : monitor.lastStatus
                                }
                            />
                        </dd>
                    </div>
                    <div>
                        <dt>Monitoring activity</dt>
                        <dd>
                            <StateBadge state={monitor.state} />
                        </dd>
                    </div>
                    <div>
                        <dt>Planned maintenance</dt>
                        <dd>
                            {ongoing ? (
                                <Badge tone="info">Maintenance in progress</Badge>
                            ) : (
                                'No ongoing maintenance'
                            )}
                        </dd>
                    </div>
                </dl>
                <p className="ui-help">
                    The last result is an observation, not a guarantee of current availability.
                    Pauses, plan suspension and maintenance are separate states.
                </p>
            </Panel>
            <Panel
                title="Check timing"
                description="Observation and schedule times use your local timezone."
            >
                <dl className="ui-monitor-facts">
                    <div>
                        <dt>Last check</dt>
                        <dd>{localInstant(monitor.lastCheckedAt, 'No check recorded')}</dd>
                    </div>
                    <div>
                        <dt>Check interval</dt>
                        <dd>{intervalLabel(monitor.intervalSeconds)}</dd>
                    </div>
                    <div>
                        <dt>Next scheduled check</dt>
                        <dd>
                            {monitor.state === 'active'
                                ? localInstant(monitor.nextCheckAt, 'Not scheduled yet')
                                : 'Not running while paused or suspended'}
                        </dd>
                    </div>
                    <div>
                        <dt>Regions</dt>
                        <dd>{monitor.regions.map(regionLabel).join(', ')}</dd>
                    </div>
                </dl>
            </Panel>
        </div>
    );
}
