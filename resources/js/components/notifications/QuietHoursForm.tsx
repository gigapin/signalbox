/** Configure daily channel silencing separately from provider credentials and verification. */
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { QuietHoursView } from './types';

/** Configuration inputs; disabling submits an explicit null interval. */
interface QuietHoursFields {
    /** Whether the daily interval applies. */
    enabled: boolean;
    /** Inclusive local HH:mm. */
    start: string;
    /** Exclusive local HH:mm, possibly on the next day. */
    end: string;
    /** Named IANA timezone, independent of browser timezone. */
    timezone: string;
}

/** An existing channel's silencing editor. */
interface QuietHoursFormProps {
    /** Team-local route of the channel. */
    path: string;
    /** Unique DOM identity prefix. */
    idPrefix: string;
    /** Current interval, or null to start disabled. */
    hours: QuietHoursView | null;
}

/** Shared accessible field styling. */
const INPUT = 'ui-input';

/** Daily silence postpones due deliveries; the editor never changes the verified recipient. */
export default function QuietHoursForm({ path, idPrefix, hours }: QuietHoursFormProps) {
    const form = useForm<QuietHoursFields>({
        enabled: hours !== null,
        start: hours?.start ?? '22:00',
        end: hours?.end ?? '07:00',
        timezone: hours?.timezone ?? 'UTC',
    });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const errors = form.errors as Record<string, string | undefined>;

    /** Submit a complete interval or explicit removal; retain values after a server refusal. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked) return;
        form.transform((data) => ({
            quiet_hours: data.enabled
                ? { start: data.start, end: data.end, timezone: data.timezone }
                : null,
        }));
        mutation.run(`${path}/quiet-hours`, (options) =>
            form.patch(`${path}/quiet-hours`, options),
        );
    }

    return (
        <form onSubmit={submit} noValidate>
            <fieldset disabled={blocked} className="min-w-0 mt-3 grid gap-3 md:grid-cols-3">
                <label className="flex items-center gap-2 md:col-span-3">
                    <input
                        type="checkbox"
                        checked={form.data.enabled}
                        onChange={(event) => form.setData('enabled', event.target.checked)}
                    />
                    Enable daily quiet hours
                </label>
                {(
                    [
                        { name: 'start', label: 'Quiet hours start', type: 'time' },
                        { name: 'end', label: 'Quiet hours end', type: 'time' },
                        { name: 'timezone', label: 'Quiet hours timezone', type: 'text' },
                    ] as const
                ).map((field) => {
                    const id = `${idPrefix}-quiet-${field.name}`;
                    const error = errors[`quiet_hours.${field.name}`];

                    return (
                        <div key={field.name}>
                            <label htmlFor={id} className="mb-1 block text-sm font-medium">
                                {field.label}
                            </label>
                            <input
                                id={id}
                                type={field.type}
                                value={form.data[field.name]}
                                disabled={!form.data.enabled}
                                onChange={(event) => form.setData(field.name, event.target.value)}
                                aria-invalid={error !== undefined}
                                aria-describedby={`${idPrefix}-quiet-hint ${id}-error`}
                                className={INPUT}
                            />
                            {error && (
                                <p id={`${id}-error`} role="alert" className="mt-1 text-red-300">
                                    {error}
                                </p>
                            )}
                        </div>
                    );
                })}
                <p
                    id={`${idPrefix}-quiet-hint`}
                    className="text-sm text-[var(--color-ui-muted)] md:col-span-3"
                >
                    Every day in the chosen IANA timezone, such as Europe/Rome or America/New_York.
                    The start is included and the end excluded; an earlier end continues past
                    midnight. Due incident notifications wait until quiet hours end. If the incident
                    is still open, one alert groups its due steps for this channel. Incidents
                    resolved before any alert was sent do not trigger a late opening. Recovery
                    summaries and retries also wait. Verification codes remain available.
                </p>
                {errors.quiet_hours && (
                    <p role="alert" className="text-red-300 md:col-span-3">
                        {errors.quiet_hours}
                    </p>
                )}
                <button
                    disabled={form.processing}
                    className="justify-self-start rounded border border-emerald-400 px-3 py-2 disabled:opacity-50"
                >
                    {form.processing ? 'Saving…' : 'Save quiet hours'}
                </button>
            </fieldset>
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={path.split('/notification-channels')[0] + '/notifications'}
                success="Configuration saved"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}
