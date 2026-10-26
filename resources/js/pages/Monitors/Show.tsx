/** Show one monitor's safe configuration and offer pause, resume, edit and delete when permitted. */
import { Link, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AuthLayout from '../../components/auth/AuthLayout';
import { StateBadge, StatusBadge } from '../../components/monitors/MonitorBadges';
import HeartbeatPanel from '../../components/monitors/HeartbeatPanel';
import { intervalLabel, regionLabel, typeLabel } from '../../components/monitors/labels';
import type {
    MonitorConfigurationView,
    MonitorDetail,
    MonitorPageProps,
} from '../../components/monitors/types';
import { monitorPath, monitorsPath } from '../../components/monitors/urls';

/** Detail page response from MonitorPageController::show. */
interface ShowProps extends MonitorPageProps {
    /** Safe detail projection. */
    monitor: MonitorDetail;
    /** Interface hints derived from MonitorPolicy. */
    can: { update: boolean; delete: boolean; pause: boolean; resume: boolean };
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
                <Detail term="URL">
                    <code className="break-all">{c.url}</code>
                </Detail>
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
                <Detail term="Host">{`${c.tls_host ?? ''}:${c.tls_port ?? ''}`}</Detail>
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
                <Detail term="Query">{`${c.dns_name ?? ''} ${c.dns_record_type ?? ''}`}</Detail>
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

/** Keep actions in one form so their shared processing state prevents concurrent submissions. */
export default function Show() {
    const { team, monitor, can } = usePage<ShowProps>().props;
    const form = useForm<Record<string, never>>({});
    const path = monitorPath(team.id, monitor.id);

    function pause() {
        form.post(`${path}/pause`, { preserveScroll: true });
    }

    function resume() {
        form.post(`${path}/resume`, { preserveScroll: true });
    }

    function destroy() {
        if (!window.confirm(`Delete ${monitor.name}? This cannot be undone.`)) {
            return;
        }

        form.delete(path);
    }

    return (
        <AuthLayout title={monitor.name} description={`${typeLabel(monitor.type)} monitor`} wide>
            <nav
                aria-label="Monitor navigation"
                className="mb-6 flex gap-4 text-sm text-emerald-300"
            >
                <Link href={monitorsPath(team.id)}>Back to monitors</Link>
                {can.update && <Link href={`${path}/edit`}>Edit monitor</Link>}
            </nav>

            {Object.entries(form.errors).map(([key, message]) => (
                <p key={key} role="alert" className="mb-4 text-red-300">
                    {message}
                </p>
            ))}

            {monitor.state === 'suspended' && (
                <p className="mb-4 rounded border border-red-800 p-3 text-red-200">
                    This monitor is suspended because the team&apos;s plan no longer covers it.
                    Resuming it requires a plan change.
                </p>
            )}
            {!monitor.withinQuota && monitor.state !== 'suspended' && (
                <p className="mb-4 rounded border border-amber-700 p-3 text-amber-200">
                    This monitor is outside the current plan quota and cannot be resumed until older
                    monitors are deleted or the plan is upgraded.
                </p>
            )}

            <div className="mb-6 flex flex-wrap gap-3">
                <StatusBadge status={monitor.lastStatus} />
                <StateBadge state={monitor.state} />
            </div>

            <dl>
                <Detail term="Interval">{intervalLabel(monitor.intervalSeconds)}</Detail>
                <Detail term="Timeout">{`${monitor.timeoutMs} ms`}</Detail>
                <Detail term="Failures before alerting">{monitor.confirmationThreshold}</Detail>
                <Detail term="Regions">{monitor.regions.map(regionLabel).join(', ')}</Detail>
                <Detail term="Last check">{instant(monitor.lastCheckedAt, 'Never')}</Detail>
                <Detail term="Next check">
                    {instant(monitor.nextCheckAt, 'Not scheduled yet')}
                </Detail>
                <Detail term="Created">{instant(monitor.createdAt, 'Unknown')}</Detail>
                <Configuration configuration={monitor.configuration} />
            </dl>

            {monitor.heartbeat && (
                <HeartbeatPanel
                    key={path}
                    path={path}
                    heartbeat={monitor.heartbeat}
                    canManage={can.update}
                />
            )}

            <div className="mt-8 flex flex-wrap gap-3">
                {monitor.state === 'active' && can.pause && (
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={pause}
                        className="rounded border border-slate-600 px-4 py-2"
                    >
                        Pause monitor
                    </button>
                )}
                {monitor.state === 'paused' && can.resume && (
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={resume}
                        className="rounded border border-slate-600 px-4 py-2"
                    >
                        Resume monitor
                    </button>
                )}
                {can.delete && (
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={destroy}
                        className="rounded border border-red-700 px-4 py-2 text-red-200"
                    >
                        Delete monitor
                    </button>
                )}
            </div>
        </AuthLayout>
    );
}
