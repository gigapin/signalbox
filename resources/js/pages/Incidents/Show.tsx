/** Show one incident with its timeline and let managers take responsibility, post, classify and publish. */
import { Link, useForm, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import { useLivePage } from '../../hooks/useLivePage';
import type { FormEvent, ReactNode } from 'react';
import AuthLayout from '../../components/auth/AuthLayout';
import { IncidentStatusBadge, SeverityBadge } from '../../components/incidents/IncidentBadges';
import {
    causeLabel,
    durationLabel,
    incidentStatusLabel,
    severityLabel,
} from '../../components/incidents/labels';
import { formatInstant } from '../../components/incidents/time';
import type {
    IncidentDetail,
    IncidentOptions,
    IncidentPageProps,
    TimelineEntry,
} from '../../components/incidents/types';
import { incidentPath, incidentsPath } from '../../components/incidents/urls';
import { monitorPath } from '../../components/monitors/urls';
import DeliveryLog from '../../components/notifications/DeliveryLog';
import type { DeliveryView } from '../../components/notifications/types';

/** Detail page response from IncidentPageController::show. */
interface ShowProps extends IncidentPageProps {
    /** Detail projection with the oldest-first timeline. */
    incident: IncidentDetail;
    /** Notification deliveries of the incident, oldest first. */
    deliveries: DeliveryView[];
    /** Status values a new timeline entry may carry; only `resolved` once resolved. */
    postableStatuses: string[];
    /** Enum values offered by the forms. */
    options: IncidentOptions;
    /** Longest accepted message or declared cause, in characters. */
    textMaxLength: number;
    /** Interface hints derived from IncidentPolicy. */
    can: { update: boolean };
    /** Validation and domain errors; `incident` carries lifecycle refusals. */
    errors: Record<string, string>;
}

/** One term/description pair. */
interface DetailProps {
    /** Term label. */
    term: string;
    /** Displayed value. */
    children: ReactNode;
}

/** Inputs shared by the management forms. */
interface ManageProps {
    /** Incident page path; mutation endpoints are below it. */
    path: string;
    /** Displayed incident. */
    incident: IncidentDetail;
}

/** Inputs of the new-entry form. */
interface PostUpdateProps extends ManageProps {
    /** Status values the entry may carry. */
    statuses: string[];
    /** Longest accepted message. */
    maxLength: number;
}

/** Inputs of the severity form. */
interface SeverityProps extends ManageProps {
    /** Offered severities. */
    severities: string[];
}

/** Inputs of the declared-cause form. */
interface RootCauseProps extends ManageProps {
    /** Longest accepted note. */
    maxLength: number;
}

/** Inputs of one timeline entry. */
interface EntryProps {
    /** Incident page path. */
    path: string;
    /** Displayed entry. */
    entry: TimelineEntry;
    /** Whether the publish control is offered. */
    canPublish: boolean;
}

/** Field styling shared by the inputs. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/** Primary button styling. */
const BUTTON = 'rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50';

/** Render one row of the description list. */
function Detail({ term, children }: DetailProps) {
    return (
        <div className="grid gap-1 border-b border-slate-800 py-2 md:grid-cols-3">
            <dt className="text-sm text-slate-400">{term}</dt>
            <dd className="md:col-span-2">{children}</dd>
        </div>
    );
}

/** Add a private entry; choosing `resolved` on an open incident resolves it. */
function PostUpdateForm({ path, incident, statuses, maxLength }: PostUpdateProps) {
    const form = useForm({ status: incident.status, message: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`${path}/updates`, {
            preserveScroll: true,
            onSuccess: () => form.reset('message'),
        });
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            aria-labelledby="post-update-heading"
            className="grid gap-3"
        >
            <h3 id="post-update-heading" className="font-medium">
                Add a timeline update
            </h3>
            <div>
                <label htmlFor="update-status" className="mb-2 block text-sm font-medium">
                    Status after this update
                </label>
                <select
                    id="update-status"
                    value={form.data.status}
                    onChange={(event) => form.setData('status', event.target.value)}
                    aria-invalid={form.errors.status !== undefined}
                    aria-describedby="update-status-error"
                    className={INPUT}
                >
                    {statuses.map((value) => (
                        <option key={value} value={value}>
                            {incidentStatusLabel(value)}
                        </option>
                    ))}
                </select>
                {form.errors.status && (
                    <p id="update-status-error" role="alert" className="mt-1 text-red-300">
                        {form.errors.status}
                    </p>
                )}
            </div>
            <div>
                <label htmlFor="update-message" className="mb-2 block text-sm font-medium">
                    Message
                </label>
                <textarea
                    id="update-message"
                    required
                    rows={3}
                    maxLength={maxLength}
                    value={form.data.message}
                    onChange={(event) => form.setData('message', event.target.value)}
                    aria-invalid={form.errors.message !== undefined}
                    aria-describedby="update-message-hint update-message-error"
                    className={INPUT}
                />
                <p id="update-message-hint" className="mt-1 text-xs text-slate-400">
                    Updates are private until you publish them.
                    {incident.status === 'resolved'
                        ? ' A resolved incident accepts notes only; a new confirmed failure reopens it.'
                        : ''}
                </p>
                {form.errors.message && (
                    <p id="update-message-error" role="alert" className="mt-1 text-red-300">
                        {form.errors.message}
                    </p>
                )}
            </div>
            <div>
                <button disabled={form.processing} className={BUTTON}>
                    {form.processing ? 'Saving…' : 'Add update'}
                </button>
            </div>
        </form>
    );
}

/** Change the impact; an unchanged value records nothing. */
function SeverityForm({ path, incident, severities }: SeverityProps) {
    const form = useForm({ severity: incident.severity });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`${path}/severity`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} noValidate className="flex flex-wrap items-end gap-3">
            <div>
                <label htmlFor="incident-severity" className="mb-2 block text-sm font-medium">
                    Severity
                </label>
                <select
                    id="incident-severity"
                    value={form.data.severity}
                    onChange={(event) => form.setData('severity', event.target.value)}
                    aria-invalid={form.errors.severity !== undefined}
                    aria-describedby="incident-severity-error"
                    className={INPUT}
                >
                    {severities.map((value) => (
                        <option key={value} value={value}>
                            {severityLabel(value)}
                        </option>
                    ))}
                </select>
            </div>
            <button disabled={form.processing} className={BUTTON}>
                {form.processing ? 'Saving…' : 'Save severity'}
            </button>
            {form.errors.severity && (
                <p id="incident-severity-error" role="alert" className="w-full text-red-300">
                    {form.errors.severity}
                </p>
            )}
        </form>
    );
}

/** Declare or clear the cause; the timeline records only that it changed, never the note itself. */
function RootCauseForm({ path, incident, maxLength }: RootCauseProps) {
    const form = useForm({ root_cause_note: incident.rootCauseNote ?? '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(`${path}/root-cause`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-3">
            <div>
                <label htmlFor="root-cause" className="mb-2 block text-sm font-medium">
                    Declared cause
                </label>
                <textarea
                    id="root-cause"
                    rows={3}
                    maxLength={maxLength}
                    value={form.data.root_cause_note}
                    onChange={(event) => form.setData('root_cause_note', event.target.value)}
                    aria-invalid={form.errors.root_cause_note !== undefined}
                    aria-describedby="root-cause-hint root-cause-error"
                    className={INPUT}
                />
                <p id="root-cause-hint" className="mt-1 text-xs text-slate-400">
                    Leave empty to clear it. The note stays internal; the timeline only records the
                    change.
                </p>
                {form.errors.root_cause_note && (
                    <p id="root-cause-error" role="alert" className="mt-1 text-red-300">
                        {form.errors.root_cause_note}
                    </p>
                )}
            </div>
            <div>
                <button disabled={form.processing} className={BUTTON}>
                    {form.processing ? 'Saving…' : 'Save cause'}
                </button>
            </div>
        </form>
    );
}

/** Show one entry with its visibility; private entries can be published once, after confirmation. */
function Entry({ path, entry, canPublish }: EntryProps) {
    const form = useForm<Record<string, never>>({});

    function publish() {
        const question =
            'Publish this update? Published updates can appear on public status pages and cannot be withdrawn.';

        if (!window.confirm(question)) {
            return;
        }

        form.post(`${path}/updates/${entry.id}/publish`, { preserveScroll: true });
    }

    return (
        <li className="border-b border-slate-800 py-3">
            <p className="text-sm text-slate-400">
                {formatInstant(entry.createdAt, 'Unknown time')} ·{' '}
                {entry.author ?? 'System or deleted account'} · {incidentStatusLabel(entry.status)}
            </p>
            <p className="mt-1 whitespace-pre-line">{entry.message}</p>
            <p className="mt-1 text-sm">
                {entry.isPublic ? (
                    <span className="text-emerald-200">
                        Published {formatInstant(entry.publishedAt, '')}
                    </span>
                ) : (
                    <span className="text-slate-300">Private</span>
                )}
            </p>
            {canPublish && !entry.isPublic && (
                <button
                    type="button"
                    disabled={form.processing}
                    onClick={publish}
                    className="mt-2 rounded border border-slate-600 px-3 py-1 text-sm disabled:opacity-50"
                >
                    {form.processing ? 'Publishing…' : 'Publish update'}
                </button>
            )}
        </li>
    );
}

/** Responsibility is offered only while open; forms re-render from the server after every change. */
export default function Show() {
    const initial = usePage<ShowProps>().props;
    const live = useLivePage<ShowProps>(
        initial.team.id,
        'Incidents/Show',
        initial.incident.monitor.id,
    );
    const { team, incident, deliveries, postableStatuses, options, textMaxLength, can, errors } =
        live.props;
    const acknowledge = useForm<Record<string, never>>({});
    const path = incidentPath(team.id, incident.id);
    const open = incident.status !== 'resolved';

    function takeResponsibility() {
        acknowledge.post(`${path}/acknowledge`, { preserveScroll: true });
    }

    return (
        <AuthLayout
            title={`Incident #${incident.id}`}
            description={`${incident.monitor.name} · ${causeLabel(incident.cause)}`}
            wide
        >
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <nav
                aria-label="Incident navigation"
                className="mb-6 flex gap-4 text-sm text-emerald-300"
            >
                <Link href={incidentsPath(team.id)}>Back to incidents</Link>
                <Link href={monitorPath(team.id, incident.monitor.id)}>
                    Monitor {incident.monitor.name}
                </Link>
            </nav>

            {errors.incident && (
                <p role="alert" className="mb-4 text-red-300">
                    {errors.incident}
                </p>
            )}

            <div className="mb-6 flex flex-wrap gap-3">
                <IncidentStatusBadge status={incident.status} />
                <SeverityBadge severity={incident.severity} />
            </div>

            <dl>
                <Detail term="First failure">{formatInstant(incident.startedAt, '')}</Detail>
                <Detail term="Confirmed">{formatInstant(incident.confirmedAt, '')}</Detail>
                <Detail term="Resolved">{formatInstant(incident.resolvedAt, 'Not yet')}</Detail>
                <Detail term="Duration">
                    {incident.durationSeconds === null
                        ? 'Ongoing'
                        : durationLabel(incident.durationSeconds)}
                </Detail>
                <Detail term="Detected cause">{causeLabel(incident.cause)}</Detail>
                <Detail term="Declared cause">
                    {incident.rootCauseNote === null ? (
                        'None declared'
                    ) : (
                        <span className="whitespace-pre-line">{incident.rootCauseNote}</span>
                    )}
                </Detail>
                <Detail term="Responsible">
                    {incident.acknowledgedAt === null
                        ? 'Nobody yet'
                        : `${incident.responsible ?? 'A deleted account'} since `}
                    {incident.acknowledgedAt !== null && formatInstant(incident.acknowledgedAt, '')}
                </Detail>
                <Detail term="Reopened">
                    {incident.reopenedCount === 0
                        ? 'Never'
                        : `${incident.reopenedCount} time${incident.reopenedCount === 1 ? '' : 's'}, last `}
                    {incident.lastReopenedAt !== null && formatInstant(incident.lastReopenedAt, '')}
                </Detail>
            </dl>

            {can.update && open && (
                <div className="mt-6">
                    <button
                        type="button"
                        disabled={acknowledge.processing}
                        onClick={takeResponsibility}
                        className="rounded border border-slate-600 px-4 py-2 disabled:opacity-50"
                    >
                        {acknowledge.processing ? 'Saving…' : 'Take responsibility'}
                    </button>
                </div>
            )}

            <section aria-labelledby="timeline-heading" className="mt-8">
                <h2 id="timeline-heading" className="mb-2 text-lg font-semibold">
                    Timeline
                </h2>
                {incident.timeline.length === 0 ? (
                    <p className="text-slate-300">No timeline entries.</p>
                ) : (
                    <ol aria-label="Timeline entries, oldest first">
                        {incident.timeline.map((entry) => (
                            <Entry
                                key={entry.id}
                                path={path}
                                entry={entry}
                                canPublish={can.update}
                            />
                        ))}
                    </ol>
                )}
            </section>

            <DeliveryLog deliveries={deliveries} />

            {can.update ? (
                <section
                    aria-label="Manage incident"
                    className="mt-8 grid gap-6 rounded border border-slate-700 p-4"
                >
                    <PostUpdateForm
                        key={`post-${incident.status}`}
                        path={path}
                        incident={incident}
                        statuses={postableStatuses}
                        maxLength={textMaxLength}
                    />
                    <SeverityForm
                        key={`severity-${incident.severity}`}
                        path={path}
                        incident={incident}
                        severities={options.severities}
                    />
                    <RootCauseForm path={path} incident={incident} maxLength={textMaxLength} />
                </section>
            ) : (
                <p className="mt-8 text-sm text-slate-400">
                    Your role has read-only access to incidents.
                </p>
            )}
        </AuthLayout>
    );
}
