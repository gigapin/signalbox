/** Present authorized monitor operations and time-weighted availability without inventing observations or continuous uptime. */
import type { MonitorMetricsView, MonitorContextView } from '../../components/monitors/metrics';
import { Link, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import { useLivePage } from '../../hooks/useLivePage';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import Panel from '../../components/ui/Panel';
import Alert from '../../components/ui/Alert';
import MonitorActions, { type MonitorPermissions } from '../../components/monitors/MonitorActions';
import MonitorSnapshot, { monitorDestination } from '../../components/monitors/MonitorSnapshot';
import AvailabilitySummary from '../../components/monitors/AvailabilitySummary';
import AvailabilityHistory from '../../components/monitors/AvailabilityHistory';
import MonitorCharts from '../../components/monitors/MonitorCharts';
import AppLayout from '../../components/app/AppLayout';
import MonitorContextPanels from '../../components/monitors/MonitorContextPanels';
import MaintenancePanel from '../../components/incidents/MaintenancePanel';
import type { IncidentSummary, MaintenanceView } from '../../components/incidents/types';
import { incidentsPath, maintenancePath } from '../../components/incidents/urls';
import HeartbeatPanel from '../../components/monitors/HeartbeatPanel';
import { typeLabel } from '../../components/monitors/labels';
import EscalationPanel from '../../components/notifications/EscalationPanel';
import type { MonitorEscalationView } from '../../components/notifications/types';
import { monitorEscalationPath } from '../../components/notifications/urls';
import type {
    MonitorConfigurationView,
    MonitorDetail,
    MonitorPageProps,
} from '../../components/monitors/types';
import { monitorPath, monitorsPath } from '../../components/monitors/urls';

/** Detail page response from MonitorPageController::show. */
interface ShowProps extends MonitorPageProps {
    /** Current availability projection, absent only on older browser-history entries. */
    metrics?: MonitorMetricsView;
    /** Certificate provenance and safe effective notification targets. */
    monitorContext?: MonitorContextView;
    /** Names of malformed metric controls; raw values are never reflected. */
    rejectedMetricFilters: string[];
    /** Safe detail projection. */
    monitor: MonitorDetail;
    /** Latest incidents of this monitor, newest first. */
    recentIncidents: IncidentSummary[];
    /** Current and recently finished maintenance windows. */
    maintenance: MaintenanceView;
    /** Escalation policy choice and its effect. */
    escalation: MonitorEscalationView;
    /** Interface hints derived from MonitorPolicy. */
    can: MonitorPermissions;
}

/** One term/description pair. */
interface DetailProps {
    /** Term label. */
    term: string;
    /** Displayed value. */
    children: ReactNode;
}

/** Render one row of the description list. */
function Detail({ term, children }: DetailProps) {
    return (
        <div className="grid gap-1 border-b border-slate-800 py-2 md:grid-cols-3">
            <dt className="text-sm text-slate-400">{term}</dt>
            <dd className="md:col-span-2">{children}</dd>
        </div>
    );
}

/**
 * @param instant ISO-8601 UTC instant or null.
 * @param empty Text shown when the instant is absent.
 * @returns Localized time element or the fallback text.
 */
function instant(instant: string | null, empty: string): ReactNode {
    if (instant === null) {
        return empty;
    }

    return <time dateTime={instant}>{new Date(instant).toLocaleString()}</time>;
}

/** Inputs for the type-specific settings list. */
interface ConfigurationProps {
    /** Safe configuration projection. */
    configuration: MonitorConfigurationView;
}

/** List type-specific settings; HTTP secrets appear only as redacted URL, header names and body size. */
function Configuration({ configuration }: ConfigurationProps) {
    const c = configuration;

    if (c.type === 'http' || c.type === 'keyword') {
        return (
            <>
                <Detail term="Method">{c.method}</Detail>
                <Detail term="Headers">
                    {c.header_names && c.header_names.length > 0
                        ? `${c.header_names.join(', ')} (values hidden)`
                        : 'None'}
                </Detail>
                <Detail term="Body">
                    {c.body?.present ? `${c.body.bytes} bytes (content hidden)` : 'None'}
                </Detail>
                <Detail term="Expected status">{c.expected_status}</Detail>
                <Detail term="Follow redirects">{c.follow_redirects ? 'Yes' : 'No'}</Detail>
                {c.type === 'keyword' && (
                    <Detail term="Keyword">
                        <code>{c.expected_keyword}</code> must be {c.keyword_mode}
                    </Detail>
                )}
            </>
        );
    }

    if (c.type === 'tls') {
        return (
            <>
                <Detail term="Expiry warning">
                    {c.tls_expiry_warning_days === null || c.tls_expiry_warning_days === undefined
                        ? 'None'
                        : `${c.tls_expiry_warning_days} days`}
                </Detail>
            </>
        );
    }

    if (c.type === 'dns') {
        return (
            <>
                <Detail term="Record type">{c.dns_record_type}</Detail>
                <Detail term="Expected values">
                    <ul>
                        {(c.dns_expected_values ?? []).map((value) => (
                            <li key={value}>
                                <code className="break-all">{value}</code>
                            </li>
                        ))}
                    </ul>
                </Detail>
                <Detail term="Resolver">{c.dns_resolver ?? 'Default'}</Detail>
            </>
        );
    }

    return <Detail term="Grace period">{`${c.heartbeat_grace_seconds ?? 0} seconds`}</Detail>;
}

/** Minimal header identity; redacted settings and form drafts remain in the removable content. */
type MonitorHeading = Pick<MonitorDetail, 'id' | 'name' | 'type'>;

/** Parent shell receives only identity changes from an authorized live snapshot. */
interface PageContentProps {
    /** Update the heading without moving the live session or forms out of the removable boundary. */
    onIdentity: (identity: MonitorHeading) => void;
}

/** Keep actions in one form so their shared processing state prevents concurrent submissions. */
function PageContent({ onIdentity }: PageContentProps) {
    const initial = usePage<ShowProps>().props;
    const live = useLivePage<ShowProps>(initial.team.id, 'Monitors/Show', initial.monitor.id);
    const {
        team,
        monitor,
        recentIncidents,
        maintenance,
        escalation,
        can,
        monitorListUrl,
        metrics,
        monitorContext,
        rejectedMetricFilters,
    } = live.props;
    useEffect(() => {
        onIdentity({ id: monitor.id, name: monitor.name, type: monitor.type });
    }, [monitor.id, monitor.name, monitor.type, onIdentity]);
    const path = monitorPath(team.id, monitor.id);

    return (
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <nav aria-label="Monitor navigation" className="ui-actions mb-4">
                <Link className="ui-link" href={monitorListUrl ?? monitorsPath(team.id)}>
                    Back to monitors
                </Link>
                <Link className="ui-link" href={incidentsPath(team.id)}>
                    All incidents
                </Link>
            </nav>
            <MonitorActions
                teamId={team.id}
                monitor={monitor}
                can={can}
                returnTo={monitorListUrl ?? monitorsPath(team.id)}
            />

            {monitor.state === 'suspended' && (
                <Alert title="Monitor suspended" tone="danger">
                    This monitor is suspended because the team&apos;s plan no longer covers it.
                    Resuming it requires a plan change.
                </Alert>
            )}
            {!monitor.withinQuota && monitor.state !== 'suspended' && (
                <Alert title="Monitor outside plan quota" tone="warning">
                    This monitor is outside the current plan quota and cannot be resumed until older
                    monitors are deleted or the plan is upgraded.
                </Alert>
            )}

            <div className="ui-monitor-detail-stack">
                <section
                    aria-label="Monitor destination"
                    className="ui-monitor-destination-heading"
                >
                    <p className="ui-help">{typeLabel(monitor.type)} monitor · Destination</p>
                    <p>
                        <code>{monitorDestination(monitor.configuration)}</code>
                    </p>
                    {metrics && (
                        <p className="ui-help">
                            Metric snapshot generated{' '}
                            <time dateTime={metrics.generatedAt}>
                                {new Date(metrics.generatedAt).toLocaleString()}
                            </time>{' '}
                            (local time).
                        </p>
                    )}
                </section>
                <MonitorSnapshot monitor={monitor} maintenance={maintenance} />
                <AvailabilityHistory metrics={metrics} />
                <AvailabilitySummary metrics={metrics} />
                <MonitorCharts
                    teamId={team.id}
                    monitorId={monitor.id}
                    returnTo={monitorListUrl ?? monitorsPath(team.id)}
                    metrics={metrics}
                    rejected={rejectedMetricFilters ?? []}
                />
                <Panel
                    title="Monitor settings"
                    description="Stored configuration is shown with sensitive values redacted."
                >
                    <dl>
                        <Detail term="Timeout">{`${monitor.timeoutMs} ms`}</Detail>
                        <Detail term="Failures before alerting">
                            {monitor.confirmationThreshold}
                        </Detail>
                        <Detail term="Created (local time)">
                            {instant(monitor.createdAt, 'Unknown')}
                        </Detail>
                        <Configuration configuration={monitor.configuration} />
                    </dl>
                </Panel>

                {monitor.heartbeat && (
                    <HeartbeatPanel
                        key={path}
                        path={path}
                        heartbeat={monitor.heartbeat}
                        canManage={can.update}
                    />
                )}

                <MonitorContextPanels
                    teamId={team.id}
                    context={monitorContext}
                    maintenance={maintenance}
                    incidents={recentIncidents}
                    monitorListUrl={monitorListUrl ?? monitorsPath(team.id)}
                />

                <MaintenancePanel
                    path={maintenancePath(team.id, monitor.id)}
                    maintenance={maintenance}
                    canManage={can.update}
                />

                <EscalationPanel
                    key={monitor.id}
                    teamId={team.id}
                    action={monitorEscalationPath(team.id, monitor.id)}
                    escalation={escalation}
                    canManage={can.update}
                />
            </div>
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team, monitor, monitorListUrl } = usePage<ShowProps>().props;
    const [heading, setHeading] = useState<MonitorHeading>({
        id: monitor.id,
        name: monitor.name,
        type: monitor.type,
    });
    const onIdentity = useCallback((next: MonitorHeading) => {
        setHeading((current) =>
            current.id === next.id && current.name === next.name && current.type === next.type
                ? current
                : next,
        );
    }, []);
    const identity = heading.id === monitor.id ? heading : monitor;
    return (
        <AppLayout
            team={team}
            section="monitors"
            ancestors={[{ label: 'Monitors', href: monitorListUrl ?? monitorsPath(team.id) }]}
            title={identity.name}
            description={`${typeLabel(identity.type)} monitor`}
        >
            <PageContent onIdentity={onIdentity} />
        </AppLayout>
    );
}
