/** List a monitor's maintenance windows and let managers schedule, reschedule, end or delete them. */
import { useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { phaseLabel } from './labels';
import { formatInstant, fromLocalInput, toLocalInput } from './time';
import type { MaintenanceView, MaintenanceWindowView } from './types';

/** Panel inputs. */
interface MaintenancePanelProps {
    /** Collection path of the monitor's maintenance windows. */
    path: string;
    /** Current and finished windows with the reason limit. */
    maintenance: MaintenanceView;
    /** Whether MonitorPolicy lets the actor manage the monitor; the server decides again. */
    canManage: boolean;
}

/** Form inputs for scheduling or rescheduling one window. */
interface MaintenanceFormProps {
    /** Element identity prefix, unique per form on the page. */
    idPrefix: string;
    /** Values shown initially. */
    initial: MaintenanceFields;
    /** Endpoint receiving the submission. */
    action: string;
    /** `post` schedules a new window; `patch` reschedules an existing one. */
    method: 'post' | 'patch';
    /** Submit button text. */
    submitLabel: string;
    /** Longest accepted reason, in characters. */
    reasonMaxLength: number;
    /** Called after a successful submission, such as closing an inline editor. */
    onSaved?: () => void;
}

/** Row inputs for one window. */
interface WindowRowProps {
    /** Collection path of the monitor's maintenance windows. */
    path: string;
    /** Displayed window. */
    window: MaintenanceWindowView;
    /** Whether management controls are offered. */
    canManage: boolean;
    /** Longest accepted reason, in characters. */
    reasonMaxLength: number;
}

/** Browser-local form values; instants are converted only when submitting. */
interface MaintenanceFields {
    /** Local `datetime-local` start; empty starts now. */
    starts_at: string;
    /** Local `datetime-local` end. */
    ends_at: string;
    /** Explanation. */
    reason: string;
}

/** Field styling shared by the inputs. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/** Values of a blank scheduling form. */
const BLANK: MaintenanceFields = { starts_at: '', ends_at: '', reason: '' };

/** Schedule or reschedule a window, converting browser-local input to UTC with an explicit offset. */
function MaintenanceForm({
    idPrefix,
    initial,
    action,
    method,
    submitLabel,
    reasonMaxLength,
    onSaved,
}: MaintenanceFormProps) {
    const form = useForm<MaintenanceFields>(initial);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            starts_at: fromLocalInput(data.starts_at),
            ends_at: fromLocalInput(data.ends_at) ?? '',
            reason: data.reason,
        }));
        form.submit(method, action, {
            preserveScroll: true,
            onSuccess: () => {
                if (method === 'post') {
                    form.reset();
                }

                onSaved?.();
            },
        });
    }

    /**
     * @param field Form field.
     * @returns Element identity of the field's error message.
     */
    function errorId(field: keyof MaintenanceFields): string {
        return `${idPrefix}-${field}-error`;
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4 md:grid-cols-2">
            <div>
                <label htmlFor={`${idPrefix}-starts_at`} className="mb-2 block text-sm font-medium">
                    Start (your local time)
                </label>
                <input
                    id={`${idPrefix}-starts_at`}
                    type="datetime-local"
                    value={form.data.starts_at}
                    onChange={(event) => form.setData('starts_at', event.target.value)}
                    aria-invalid={form.errors.starts_at !== undefined}
                    aria-describedby={`${idPrefix}-starts_at-hint ${errorId('starts_at')}`}
                    className={INPUT}
                />
                <p id={`${idPrefix}-starts_at-hint`} className="mt-1 text-xs text-slate-400">
                    Leave empty to start now. A start in the past is refused.
                </p>
                {form.errors.starts_at && (
                    <p id={errorId('starts_at')} role="alert" className="mt-1 text-red-300">
                        {form.errors.starts_at}
                    </p>
                )}
            </div>
            <div>
                <label htmlFor={`${idPrefix}-ends_at`} className="mb-2 block text-sm font-medium">
                    End (your local time)
                </label>
                <input
                    id={`${idPrefix}-ends_at`}
                    type="datetime-local"
                    required
                    value={form.data.ends_at}
                    onChange={(event) => form.setData('ends_at', event.target.value)}
                    aria-invalid={form.errors.ends_at !== undefined}
                    aria-describedby={errorId('ends_at')}
                    className={INPUT}
                />
                {form.errors.ends_at && (
                    <p id={errorId('ends_at')} role="alert" className="mt-1 text-red-300">
                        {form.errors.ends_at}
                    </p>
                )}
            </div>
            <div className="md:col-span-2">
                <label htmlFor={`${idPrefix}-reason`} className="mb-2 block text-sm font-medium">
                    Reason
                </label>
                <textarea
                    id={`${idPrefix}-reason`}
                    required
                    rows={2}
                    maxLength={reasonMaxLength}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    aria-invalid={form.errors.reason !== undefined}
                    aria-describedby={errorId('reason')}
                    className={INPUT}
                />
                {form.errors.reason && (
                    <p id={errorId('reason')} role="alert" className="mt-1 text-red-300">
                        {form.errors.reason}
                    </p>
                )}
            </div>
            <div className="md:col-span-2">
                <button
                    disabled={form.processing}
                    className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    {form.processing ? 'Saving…' : submitLabel}
                </button>
            </div>
        </form>
    );
}

/** Show one window with the change its phase admits. */
function WindowRow({ path, window: item, canManage, reasonMaxLength }: WindowRowProps) {
    const [editing, setEditing] = useState(false);
    const remove = useForm<Record<string, never>>({});
    const url = `${path}/${item.id}`;
    const upcoming = item.phase === 'upcoming';

    function cancel() {
        const question = upcoming
            ? 'Delete this upcoming maintenance window?'
            : 'End this maintenance window now? Incidents can open again immediately.';

        if (!window.confirm(question)) {
            return;
        }

        remove.delete(url, { preserveScroll: true });
    }

    return (
        <li className="border-b border-slate-800 py-3">
            <p className="font-medium">{item.reason}</p>
            <p className="text-sm text-slate-300">
                {formatInstant(item.startsAt, '')} – {formatInstant(item.endsAt, '')} ·{' '}
                {phaseLabel(item.phase)}
            </p>
            {canManage && item.phase !== 'finished' && (
                <div className="mt-2 flex flex-wrap gap-3 text-sm">
                    {upcoming && (
                        <button
                            type="button"
                            aria-expanded={editing}
                            onClick={() => setEditing(!editing)}
                            className="underline"
                        >
                            {editing ? 'Close editor' : 'Edit window'}
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={remove.processing}
                        onClick={cancel}
                        className="text-red-200 underline disabled:opacity-50"
                    >
                        {upcoming ? 'Delete window' : 'End now'}
                    </button>
                </div>
            )}
            {editing && (
                <div className="mt-3 rounded border border-slate-700 p-3">
                    <MaintenanceForm
                        idPrefix={`maintenance-${item.id}`}
                        initial={{
                            starts_at: toLocalInput(item.startsAt),
                            ends_at: toLocalInput(item.endsAt),
                            reason: item.reason,
                        }}
                        action={url}
                        method="patch"
                        submitLabel="Save window"
                        reasonMaxLength={reasonMaxLength}
                        onSaved={() => setEditing(false)}
                    />
                </div>
            )}
        </li>
    );
}

/**
 * Maintenance stops new incidents from opening while it lasts. Domain refusals arrive on the `maintenance`
 * error key and are announced above the lists.
 */
export default function MaintenancePanel({ path, maintenance, canManage }: MaintenancePanelProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <section aria-labelledby="maintenance-heading" className="mt-8">
            <h2 id="maintenance-heading" className="mb-2 text-lg font-semibold">
                Maintenance
            </h2>
            <p className="mb-4 text-sm text-slate-400">
                While a window is in progress, confirmed failures open no incident. Times are shown
                in your browser&apos;s timezone.
            </p>

            {errors.maintenance && (
                <p role="alert" className="mb-4 text-red-300">
                    {errors.maintenance}
                </p>
            )}

            {maintenance.current.length === 0 ? (
                <p className="text-slate-300">No maintenance in progress or scheduled.</p>
            ) : (
                <ul aria-label="Current and upcoming maintenance">
                    {maintenance.current.map((item) => (
                        <WindowRow
                            key={item.id}
                            path={path}
                            window={item}
                            canManage={canManage}
                            reasonMaxLength={maintenance.reasonMaxLength}
                        />
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="mt-4 rounded border border-slate-700 p-4">
                    <h3 className="mb-3 font-medium">Schedule maintenance</h3>
                    <MaintenanceForm
                        idPrefix="maintenance-new"
                        initial={BLANK}
                        action={path}
                        method="post"
                        submitLabel="Schedule maintenance"
                        reasonMaxLength={maintenance.reasonMaxLength}
                    />
                </div>
            )}

            {maintenance.finished.length > 0 && (
                <>
                    <h3 className="mt-6 mb-2 font-medium">Recently finished</h3>
                    <ul aria-label="Finished maintenance">
                        {maintenance.finished.map((item) => (
                            <WindowRow
                                key={item.id}
                                path={path}
                                window={item}
                                canManage={false}
                                reasonMaxLength={maintenance.reasonMaxLength}
                            />
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
