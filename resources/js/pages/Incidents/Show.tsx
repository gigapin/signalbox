/** Show one incident with its timeline and let managers take responsibility, post, classify and publish. */
import { useFormProtection, useNavigationGuard } from '../../components/app/NavigationGuard';
import { Link, useForm, usePage } from '@inertiajs/react';
import LiveUpdates from '../../components/LiveUpdates';
import { useLivePage } from '../../hooks/useLivePage';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Panel from '../../components/ui/Panel';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../../components/MutationFeedback';
import AppLayout from '../../components/app/AppLayout';
import { IncidentStatusBadge, SeverityBadge } from '../../components/incidents/IncidentBadges';
import {
    causeLabel,
    incidentDurationLabel,
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
    /** Server-normalized list and monitor returns; absent only in old history fixtures. */
    incidentListUrl?: string;
    /** Actual monitor destination with normalized monitor-list context. */
    monitorUrl?: string;
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
const INPUT = 'ui-input w-full';

/** Primary button styling. */
const BUTTON = 'ui-button';

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
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();

    const [baseline, setBaseline] = useState(incident.status);
    if (!form.isDirty && !form.processing && baseline !== incident.status) {
        setBaseline(incident.status);
        form.setData('status', incident.status);
        form.setDefaults({ status: incident.status });
    }
    const unavailable = !statuses.includes(form.data.status);

    /** Submit one private update; clearing the draft requires confirmed acceptance. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (guard.busy || unavailable) return;
        mutation.run(
            `${path}/updates`,
            (options) => form.post(`${path}/updates`, options),
            undefined,
            () => form.reset('message'),
        );
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            aria-labelledby="post-update-heading"
            className="grid gap-3"
        >
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path}
                success="Timeline update saved"
                onReviewed={mutation.acknowledge}
            />
            <fieldset
                disabled={mutation.phase === 'pending' || form.processing}
                className="ui-incident-editor-fields"
            >
                <legend className="sr-only">Private timeline update</legend>
                {unavailable && (
                    <p role="status">
                        The incident changed. Choose an available status; your message is preserved.
                    </p>
                )}
                <h3 id="post-update-heading" className="font-medium">
                    Add a timeline update
                </h3>
                <div>
                    <label htmlFor="update-status" className="ui-label">
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
                        {unavailable && (
                            <option value={form.data.status} disabled>
                                Draft status unavailable
                            </option>
                        )}
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
                    <label htmlFor="update-message" className="ui-label">
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
                    <p id="update-message-hint" className="ui-help">
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
                    <button
                        disabled={mutation.locked || form.processing || guard.busy || unavailable}
                        className={BUTTON}
                    >
                        {form.processing ? 'Saving…' : 'Add update'}
                    </button>
                </div>
            </fieldset>
        </form>
    );
}

/** Change the impact; an unchanged value records nothing. */
function SeverityForm({ path, incident, severities }: SeverityProps) {
    const form = useForm({ severity: incident.severity });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();

    const saved = incident.severity;
    const [baseline, setBaseline] = useState(saved);
    if (!form.isDirty && !form.processing && baseline !== saved) {
        setBaseline(saved);
        form.setData('severity', saved);
        form.setDefaults({ severity: saved });
    }

    /** Submit one explicit change without overwriting a pending draft. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (guard.busy) return;
        mutation.run(`${path}/severity`, (options) => form.patch(`${path}/severity`, options));
    }

    return (
        <form onSubmit={submit} noValidate className="flex flex-wrap items-end gap-3">
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path}
                success="Severity saved"
                onReviewed={mutation.acknowledge}
            />
            <fieldset
                disabled={mutation.phase === 'pending' || form.processing}
                className="ui-incident-editor-fields"
            >
                <legend className="sr-only">Severity saved</legend>
                <div>
                    <label htmlFor="incident-severity" className="ui-label">
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
                <button
                    disabled={form.processing || mutation.locked || guard.busy}
                    className={BUTTON}
                >
                    {form.processing ? 'Saving…' : 'Save severity'}
                </button>
                {form.errors.severity && (
                    <p id="incident-severity-error" role="alert" className="w-full text-red-300">
                        {form.errors.severity}
                    </p>
                )}
            </fieldset>
        </form>
    );
}

/** Declare or clear the cause; the timeline records only that it changed, never the note itself. */
function RootCauseForm({ path, incident, maxLength }: RootCauseProps) {
    const form = useForm({ root_cause_note: incident.rootCauseNote ?? '' });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();

    const saved = incident.rootCauseNote ?? '';
    const [baseline, setBaseline] = useState(saved);
    if (!form.isDirty && !form.processing && baseline !== saved) {
        setBaseline(saved);
        form.setData('root_cause_note', saved);
        form.setDefaults({ root_cause_note: saved });
    }

    /** Submit one explicit change without overwriting a pending draft. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (guard.busy) return;
        mutation.run(`${path}/root-cause`, (options) => form.patch(`${path}/root-cause`, options));
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-3">
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path}
                success="Declared cause saved"
                onReviewed={mutation.acknowledge}
            />
            <fieldset
                disabled={mutation.phase === 'pending' || form.processing}
                className="ui-incident-editor-fields"
            >
                <legend className="sr-only">Declared cause saved</legend>
                <div>
                    <label htmlFor="root-cause" className="ui-label">
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
                    <p id="root-cause-hint" className="ui-help">
                        Leave empty to clear it. The note stays internal; the timeline only records
                        the change.
                    </p>
                    {form.errors.root_cause_note && (
                        <p id="root-cause-error" role="alert" className="mt-1 text-red-300">
                            {form.errors.root_cause_note}
                        </p>
                    )}
                </div>
                <div>
                    <button
                        disabled={form.processing || mutation.locked || guard.busy}
                        className={BUTTON}
                    >
                        {form.processing ? 'Saving…' : 'Save cause'}
                    </button>
                </div>
            </fieldset>
        </form>
    );
}

/** Show one entry with its visibility; private entries can be published once, after confirmation. */
function Entry({ path, entry, canPublish }: EntryProps) {
    const form = useForm<Record<string, never>>({});
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();

    const [confirming, setConfirming] = useState(false);
    const cancelButton = useRef<HTMLButtonElement>(null);
    const publishButton = useRef<HTMLButtonElement>(null);
    if (confirming && (entry.isPublic || !canPublish)) setConfirming(false);
    useEffect(() => {
        if (confirming) cancelButton.current?.focus();
    }, [confirming]);

    /** Close an unsent publication confirmation and restore its trigger focus. */
    function cancel(): void {
        setConfirming(false);
        publishButton.current?.focus();
    }

    /** Publish once after explicit confirmation; the domain action remains idempotent. */
    function publish() {
        if (guard.busy || !canPublish || entry.isPublic) return;
        if (
            mutation.run(`${path}/updates/${entry.id}/publish`, (options) =>
                form.post(`${path}/updates/${entry.id}/publish`, options),
            )
        )
            setConfirming(false);
    }

    return (
        <li className="ui-incident-entry">
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
                    ref={publishButton}
                    type="button"
                    disabled={form.processing || mutation.locked || guard.busy}
                    onClick={() => setConfirming(true)}
                    className="ui-button mt-2"
                    data-variant="secondary"
                >
                    Publish update
                </button>
            )}
            {confirming && (
                <div
                    role="group"
                    aria-label="Confirm publication"
                    className="ui-panel mt-3"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            cancel();
                        }
                    }}
                >
                    <p>
                        Publish this update? Published updates can appear on public status pages and
                        cannot be withdrawn.
                    </p>
                    <div className="ui-actions">
                        <button
                            ref={cancelButton}
                            type="button"
                            className="ui-button"
                            data-variant="secondary"
                            onClick={cancel}
                        >
                            Cancel publication
                        </button>
                        <button
                            type="button"
                            className="ui-button"
                            disabled={mutation.locked || guard.busy}
                            onClick={publish}
                        >
                            Confirm publication
                        </button>
                    </div>
                </div>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path}
                success="Update published"
                onReviewed={mutation.acknowledge}
            />
        </li>
    );
}

/** Keep authorized incident data live while local drafts retain their own baseline. */
function PageContent() {
    const initial = usePage<ShowProps>().props;
    const live = useLivePage<ShowProps>(
        initial.team.id,
        'Incidents/Show',
        initial.incident.monitor.id,
        initial.incident.id,
    );
    const {
        team,
        incident,
        deliveries,
        postableStatuses,
        options,
        textMaxLength,
        can,
        errors,
        incidentListUrl,
        monitorUrl,
    } = live.props;
    const acknowledge = useForm<Record<string, never>>({});
    useFormProtection(acknowledge);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const path = incidentPath(team.id, incident.id);
    const open = incident.status !== 'resolved';

    function takeResponsibility() {
        if (guard.busy || !can.update || !open) return;
        mutation.run(`${path}/acknowledge`, (options) =>
            acknowledge.post(`${path}/acknowledge`, options),
        );
    }

    return (
        <>
            <LiveUpdates phase={live.phase} retry={live.retry} />
            <nav aria-label="Incident navigation" className="ui-actions mb-6">
                <Link className="ui-link" href={incidentListUrl ?? incidentsPath(team.id)}>
                    Back to incidents
                </Link>
                <Link
                    className="ui-link"
                    href={monitorUrl ?? monitorPath(team.id, incident.monitor.id)}
                >
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

            <Panel
                title="Incident summary"
                description="Timestamps use your local time. Final duration comes from the recorded incident."
            >
                <dl className="ui-monitor-facts">
                    <Detail term="First failure">{formatInstant(incident.startedAt, '')}</Detail>
                    <Detail term="Confirmed">{formatInstant(incident.confirmedAt, '')}</Detail>
                    <Detail term="Resolved">{formatInstant(incident.resolvedAt, 'Not yet')}</Detail>
                    <Detail term="Duration">{incidentDurationLabel(incident)}</Detail>
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
                        {incident.acknowledgedAt !== null &&
                            formatInstant(incident.acknowledgedAt, '')}
                    </Detail>
                    <Detail term="Reopened">
                        {incident.reopenedCount === 0
                            ? 'Never'
                            : `${incident.reopenedCount} time${incident.reopenedCount === 1 ? '' : 's'}, last `}
                        {incident.lastReopenedAt !== null &&
                            formatInstant(incident.lastReopenedAt, '')}
                    </Detail>
                </dl>
            </Panel>

            {can.update && open && (
                <div className="mt-6">
                    <button
                        type="button"
                        disabled={acknowledge.processing || mutation.locked || guard.busy}
                        onClick={takeResponsibility}
                        className="ui-button"
                        data-variant="secondary"
                    >
                        {acknowledge.processing ? 'Saving…' : 'Take responsibility'}
                    </button>
                </div>
            )}

            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path}
                success="Responsibility updated"
                onReviewed={mutation.acknowledge}
            />
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
                    className="ui-panel ui-incident-management mt-8"
                >
                    <PostUpdateForm
                        path={path}
                        incident={incident}
                        statuses={postableStatuses}
                        maxLength={textMaxLength}
                    />
                    <SeverityForm path={path} incident={incident} severities={options.severities} />
                    <RootCauseForm path={path} incident={incident} maxLength={textMaxLength} />
                </section>
            ) : (
                <p className="mt-8 text-sm text-slate-400">
                    Your role has read-only access to incidents.
                </p>
            )}
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team, incident, incidentListUrl } = usePage<ShowProps>().props;
    return (
        <AppLayout
            team={team}
            section="incidents"
            ancestors={[{ label: 'Incidents', href: incidentListUrl ?? incidentsPath(team.id) }]}
            title={`Incident #${incident.id}`}
            description="Incident details and response history."
        >
            <PageContent key={incident.id} />
        </AppLayout>
    );
}
