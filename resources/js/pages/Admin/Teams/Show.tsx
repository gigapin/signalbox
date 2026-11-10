/** Platform operator view of one team: effective plan, override form and audit history. */
import { useLeaveProtection } from '../../../components/app/NavigationGuard';
import { useFormProtection } from '../../../components/app/NavigationGuard';
import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { planLabel, sourceLabel, utcLabel } from '../../../components/admin/labels';
import {
    overridePayload,
    type Choice,
    type OverrideForm,
} from '../../../components/admin/overrideForm';
import type { AdminTeamDetail } from '../../../components/admin/types';
import type { AuthProps } from '../../../components/auth/AuthLayout';
import PrivateContextLayout from '../../../components/app/PrivateContextLayout';
import PasswordConfirmationForm from '../../../components/auth/PasswordConfirmationForm';

/** Detail response from TeamDirectoryController::show. */
interface ShowProps extends AuthProps {
    /** Team detail. */
    team: AdminTeamDetail;
    /** Override choices. */
    options: { kinds: string[]; plans: string[] };
    /** Longest accepted reason, in characters. */
    reasonMaxLength: number;
}

/** Field styling shared with the other forms. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/** Labels of the radio choices. */
const CHOICES: { value: Choice; label: string }[] = [
    { value: 'none', label: 'No override (stored plan and trial apply)' },
    { value: 'unlimited', label: 'Unlimited testing' },
    { value: 'comped-pro', label: 'Granted Pro plan' },
    { value: 'comped-business', label: 'Granted Business plan' },
];

/**
 * @param team Team detail.
 * @returns Choice matching the stored override.
 */
function currentChoice(team: AdminTeamDetail): Choice {
    if (team.override === null) {
        return 'none';
    }

    if (team.override.kind === 'unlimited') {
        return 'unlimited';
    }

    return team.override.plan === 'business' ? 'comped-business' : 'comped-pro';
}

/**
 * @param expiresAt Exclusive ISO-8601 end.
 * @returns Inclusive UTC date for the form, i.e. the day before the exclusive end.
 */
function validThrough(expiresAt: string | null): string {
    if (expiresAt === null) {
        return '';
    }

    const end = new Date(expiresAt);
    end.setUTCDate(end.getUTCDate() - 1);

    return end.toISOString().slice(0, 10);
}

/** Show the team and let the operator set or clear its override with a mandatory reason. */
export default function Show() {
    const { team, reasonMaxLength } = usePage<ShowProps>().props;
    const form = useForm<OverrideForm>({
        choice: currentChoice(team),
        valid_through: validThrough(team.override?.expiresAt ?? null),
        reason: '',
    });
    useFormProtection(form);
    const path = `/admin/teams/${team.id}/plan-override`;
    const fieldErrors = form.errors as Record<string, string | undefined>;
    const general = ['kind', 'plan']
        .map((key) => fieldErrors[key])
        .filter((message): message is string => message !== undefined);

    const [checkingPassword, setCheckingPassword] = useState(false);
    const [pendingDraft, setPendingDraft] = useState<OverrideForm | null>(null);
    const [confirmationError, setConfirmationError] = useState('');
    const passwordCheck = useRef<AbortController | null>(null);
    const saveButton = useRef<HTMLButtonElement>(null);
    const locked = form.processing || checkingPassword || pendingDraft !== null;
    useLeaveProtection(false, checkingPassword || pendingDraft !== null);
    let saveLabel = 'Save override';
    if (checkingPassword) saveLabel = 'Checking password…';
    else if (form.processing) saveLabel = 'Saving…';

    /** Stop the preflight when leaving; a late response must never trigger the stored operation. */
    useEffect(() => () => passwordCheck.current?.abort(), []);

    /** Send the exact confirmed draft through the existing, server-protected Inertia mutation. */
    function save(draft: OverrideForm): void {
        form.transform(() => overridePayload(draft));
        const visit = { preserveScroll: true, onSuccess: () => form.setData('reason', '') };

        if (draft.choice === 'none') form.delete(path, visit);
        else form.put(path, visit);
    }

    /** Read the actual server session before deciding whether the current intent needs an inline challenge. */
    async function checkPassword(draft: OverrideForm): Promise<void> {
        const operation = new AbortController();
        passwordCheck.current = operation;
        setCheckingPassword(true);
        setConfirmationError('');

        try {
            const response = await fetch('/user/confirmed-password-status', {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: operation.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok || response.redirected)
                throw new Error('Invalid confirmation status.');
            const body: unknown = await response.json();
            if (
                typeof body !== 'object' ||
                body === null ||
                !('confirmed' in body) ||
                typeof body.confirmed !== 'boolean'
            ) {
                throw new Error('Invalid confirmation status.');
            }
            if (operation.signal.aborted) return;
            if (body.confirmed) save(draft);
            else setPendingDraft(draft);
        } catch {
            if (!operation.signal.aborted)
                setConfirmationError(
                    'Unable to check password confirmation. Check your connection or sign in again.',
                );
        } finally {
            if (passwordCheck.current === operation) passwordCheck.current = null;
            if (!operation.signal.aborted) setCheckingPassword(false);
        }
    }

    /** Preserve the selected intent while confirming; no pending draft is stored in history or on the server. */
    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (locked || passwordCheck.current) return;

        const question =
            form.data.choice === 'none'
                ? `Remove the plan override of ${team.name}?`
                : `Apply this plan override to ${team.name}?`;
        if (!window.confirm(question)) return;
        void checkPassword({ ...form.data });
    }

    /** Resume only the explicit pending draft after the server accepts the password. */
    function confirmPendingDraft(): void {
        if (pendingDraft === null) return;
        const draft = pendingDraft;
        setPendingDraft(null);
        save(draft);
    }

    /** Drop the prompt without changing the draft and restore keyboard focus to its trigger. */
    function cancelPendingDraft(): void {
        setPendingDraft(null);
        window.requestAnimationFrame(() => saveButton.current?.focus());
    }

    return (
        <PrivateContextLayout
            context="platform"
            title={team.name}
            description={`Team ${team.slug}.`}
        >
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt className="text-sm text-slate-400">Owner</dt>
                    <dd>
                        {team.owner.name} ({team.owner.email})
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-400">Members</dt>
                    <dd>{team.members}</dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-400">Stored plan</dt>
                    <dd>{planLabel(team.storedPlan)}</dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-400">Effective plan</dt>
                    <dd>
                        {planLabel(team.entitlement.plan)} — {sourceLabel(team.entitlement.source)}
                        {team.entitlement.expiresAt !== null &&
                            `, until ${utcLabel(team.entitlement.expiresAt)}`}
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-400">Monitors</dt>
                    <dd>
                        {team.usage.used} of {team.usage.limit}, minimum interval{' '}
                        {team.usage.minimumIntervalSeconds} s
                    </dd>
                </div>
                <div>
                    <dt className="text-sm text-slate-400">Trial</dt>
                    <dd>
                        {utcLabel(team.trialStartedAt)} – {utcLabel(team.trialEndsAt)}
                    </dd>
                </div>
            </dl>

            <section aria-labelledby="override-heading" className="mt-10">
                <h2 id="override-heading" className="text-xl font-semibold">
                    Plan override
                </h2>
                <p className="mt-2 text-sm text-slate-300">
                    {team.override === null
                        ? 'No override is set.'
                        : `Current: ${team.override.kind === 'unlimited' ? 'unlimited testing' : `granted ${planLabel(team.override.plan ?? 'pro')}`}, ends ${utcLabel(team.override.expiresAt, 'never')}. Reason: ${team.override.reason}`}
                </p>

                {confirmationError && (
                    <p role="alert" className="mt-4 text-red-300">
                        {confirmationError}
                    </p>
                )}
                <form onSubmit={submit} className="mt-4" noValidate>
                    <fieldset disabled={locked} className="grid min-w-0 gap-4">
                        <legend className="sr-only">Plan override settings</legend>
                        {general.length > 0 && (
                            <div
                                role="alert"
                                tabIndex={-1}
                                className="rounded border border-red-800 p-3 text-red-300"
                            >
                                {general.map((message) => (
                                    <p key={message}>{message}</p>
                                ))}
                            </div>
                        )}

                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">Override</legend>
                            {CHOICES.map((choice) => (
                                <label key={choice.value} className="flex items-center gap-2 py-1">
                                    <input
                                        type="radio"
                                        name="choice"
                                        value={choice.value}
                                        checked={form.data.choice === choice.value}
                                        onChange={() => form.setData('choice', choice.value)}
                                    />
                                    {choice.label}
                                </label>
                            ))}
                        </fieldset>

                        {form.data.choice !== 'none' && (
                            <div>
                                <label
                                    htmlFor="valid-through"
                                    className="mb-2 block text-sm font-medium"
                                >
                                    Valid through (UTC date, optional)
                                </label>
                                <input
                                    id="valid-through"
                                    type="date"
                                    value={form.data.valid_through}
                                    onChange={(event) =>
                                        form.setData('valid_through', event.target.value)
                                    }
                                    aria-invalid={form.errors.valid_through !== undefined}
                                    aria-describedby="valid-through-hint valid-through-error"
                                    className={INPUT}
                                />
                                <p id="valid-through-hint" className="mt-1 text-xs text-slate-400">
                                    Leave empty to keep the override until it is removed.
                                </p>
                                {form.errors.valid_through && (
                                    <p
                                        id="valid-through-error"
                                        role="alert"
                                        className="mt-1 text-red-300"
                                    >
                                        {form.errors.valid_through}
                                    </p>
                                )}
                            </div>
                        )}

                        <div>
                            <label
                                htmlFor="override-reason"
                                className="mb-2 block text-sm font-medium"
                            >
                                Reason (required, recorded in the audit log)
                            </label>
                            <textarea
                                id="override-reason"
                                required
                                maxLength={reasonMaxLength}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                aria-invalid={form.errors.reason !== undefined}
                                aria-describedby={
                                    form.errors.reason ? 'override-reason-error' : undefined
                                }
                                className={INPUT}
                            />
                            {form.errors.reason && (
                                <p
                                    id="override-reason-error"
                                    role="alert"
                                    className="mt-1 text-red-300"
                                >
                                    {form.errors.reason}
                                </p>
                            )}
                        </div>

                        <div>
                            <button
                                ref={saveButton}
                                disabled={locked}
                                className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                            >
                                {saveLabel}
                            </button>
                        </div>
                    </fieldset>
                </form>
                {pendingDraft !== null && (
                    <PasswordConfirmationForm
                        onConfirmed={confirmPendingDraft}
                        onCancel={cancelPendingDraft}
                    />
                )}
            </section>

            <section aria-labelledby="history-heading" className="mt-10">
                <h2 id="history-heading" className="text-xl font-semibold">
                    History
                </h2>
                {team.events.length === 0 ? (
                    <p className="mt-2 text-slate-300">
                        No operator action recorded for this team.
                    </p>
                ) : (
                    <ol className="mt-3 grid gap-3">
                        {team.events.map((event) => (
                            <li
                                key={event.id}
                                className="rounded border border-slate-800 p-3 text-sm"
                            >
                                <p>
                                    <span className="font-medium">{event.action}</span> by{' '}
                                    {event.actor ?? 'command line'} on {utcLabel(event.createdAt)}
                                </p>
                                {event.reason !== null && (
                                    <p className="text-slate-300">Reason: {event.reason}</p>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </PrivateContextLayout>
    );
}
