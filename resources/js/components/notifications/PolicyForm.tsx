/** Create or change an escalation policy: ordered delays, each notifying a set of channels. */
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { ChannelView, NotificationLimits, PolicyView } from './types';

/** One editable step; the delay stays a string while typing. */
interface StepFields {
    /** Minutes after confirmation, as typed. */
    after_minutes: string;
    /** Selected channel identifiers. */
    channel_ids: number[];
}

/** Submitted values. */
interface PolicyFields {
    /** Label unique within the team. */
    name: string;
    /** Whether the policy becomes the team default. */
    is_default: boolean;
    /** Steps in delay order. */
    steps: StepFields[];
}

/** Form inputs. */
interface PolicyFormProps {
    /** Element identity prefix, unique per form on the page. */
    idPrefix: string;
    /** Endpoint receiving the submission. */
    action: string;
    /** `post` creates; `patch` changes an existing policy. */
    method: 'post' | 'patch';
    /** Policy being edited, or null on creation. */
    policy: PolicyView | null;
    /** Channels a step may notify. */
    channels: ChannelView[];
    /** Form bounds. */
    limits: NotificationLimits;
    /** Submit button text. */
    submitLabel: string;
    /** Called after a successful submission. */
    onSaved?: () => void;
}

/** Field styling shared by the inputs. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

/**
 * @param policy Policy being edited, or null.
 * @returns Initial values: the policy's own, or one immediate step without channels.
 */
function initialFields(policy: PolicyView | null): PolicyFields {
    if (policy === null) {
        return { name: '', is_default: false, steps: [{ after_minutes: '0', channel_ids: [] }] };
    }

    return {
        name: policy.name,
        is_default: policy.isDefault,
        steps: policy.steps.map((step) => ({
            after_minutes: String(step.afterMinutes),
            channel_ids: step.channels.map((channel) => channel.id),
        })),
    };
}

/** Delays are absolute from the confirmation; the server refuses steps that do not wait longer than the previous one. */
export default function PolicyForm({
    idPrefix,
    action,
    method,
    policy,
    channels,
    limits,
    submitLabel,
    onSaved,
}: PolicyFormProps) {
    const form = useForm<PolicyFields>(initialFields(policy));
    const errors = form.errors as Record<string, string | undefined>;

    /**
     * @param index Step position.
     * @param change Fields replaced in that step.
     */
    function updateStep(index: number, change: Partial<StepFields>) {
        form.setData(
            'steps',
            form.data.steps.map((step, position) =>
                position === index ? { ...step, ...change } : step,
            ),
        );
    }

    /**
     * @param index Step position.
     * @param channelId Channel toggled in that step.
     */
    function toggleChannel(index: number, channelId: number) {
        const step = form.data.steps[index];

        if (step === undefined) {
            return;
        }

        const selected = step.channel_ids.includes(channelId)
            ? step.channel_ids.filter((id) => id !== channelId)
            : [...step.channel_ids, channelId];

        updateStep(index, { channel_ids: selected });
    }

    function addStep() {
        const last = form.data.steps[form.data.steps.length - 1];
        const next = last === undefined ? 0 : Number(last.after_minutes) + 15;

        form.setData('steps', [
            ...form.data.steps,
            { after_minutes: String(next), channel_ids: [] },
        ]);
    }

    /** @param index Step removed from the policy. */
    function removeStep(index: number) {
        form.setData(
            'steps',
            form.data.steps.filter((_, position) => position !== index),
        );
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            is_default: data.is_default,
            steps: data.steps.map((step) => ({
                after_minutes: Number(step.after_minutes),
                channel_ids: step.channel_ids,
            })),
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

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <div>
                <label htmlFor={`${idPrefix}-name`} className="mb-2 block text-sm font-medium">
                    Policy name
                </label>
                <input
                    id={`${idPrefix}-name`}
                    required
                    maxLength={limits.nameMaxLength}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    aria-invalid={errors.name !== undefined}
                    aria-describedby={`${idPrefix}-name-error`}
                    className={INPUT}
                />
                {errors.name && (
                    <p id={`${idPrefix}-name-error`} role="alert" className="mt-1 text-red-300">
                        {errors.name}
                    </p>
                )}
            </div>
            <div className="flex items-center gap-2">
                <input
                    id={`${idPrefix}-default`}
                    type="checkbox"
                    checked={form.data.is_default}
                    onChange={(event) => form.setData('is_default', event.target.checked)}
                />
                <label htmlFor={`${idPrefix}-default`}>
                    Team default: used by monitors that do not choose another policy
                </label>
            </div>

            <ol className="grid gap-4" aria-label="Escalation steps">
                {form.data.steps.map((step, index) => {
                    const stepId = `${idPrefix}-step-${index}`;
                    const delayError = errors[`steps.${index}.after_minutes`];
                    const channelError = errors[`steps.${index}.channel_ids`];

                    return (
                        <li key={stepId} className="rounded border border-slate-700 p-3">
                            <fieldset>
                                <legend className="font-medium">Step {index + 1}</legend>
                                <label
                                    htmlFor={`${stepId}-delay`}
                                    className="mt-2 mb-1 block text-sm"
                                >
                                    Minutes after the incident is confirmed
                                </label>
                                <input
                                    id={`${stepId}-delay`}
                                    type="number"
                                    min={0}
                                    max={limits.maxStepDelayMinutes}
                                    value={step.after_minutes}
                                    onChange={(event) =>
                                        updateStep(index, { after_minutes: event.target.value })
                                    }
                                    aria-invalid={delayError !== undefined}
                                    aria-describedby={`${stepId}-delay-error`}
                                    className="w-32 rounded border border-slate-600 bg-slate-900 px-3 py-2"
                                />
                                {delayError && (
                                    <p
                                        id={`${stepId}-delay-error`}
                                        role="alert"
                                        className="mt-1 text-red-300"
                                    >
                                        {delayError}
                                    </p>
                                )}
                                <fieldset
                                    className="mt-3"
                                    aria-describedby={`${stepId}-channels-error`}
                                >
                                    <legend className="text-sm">Notify these channels</legend>
                                    {channels.length === 0 ? (
                                        <p className="text-sm text-slate-400">
                                            Create a channel first.
                                        </p>
                                    ) : (
                                        channels.map((channel) => (
                                            <div
                                                key={channel.id}
                                                className="flex items-center gap-2"
                                            >
                                                <input
                                                    id={`${stepId}-channel-${channel.id}`}
                                                    type="checkbox"
                                                    checked={step.channel_ids.includes(channel.id)}
                                                    onChange={() =>
                                                        toggleChannel(index, channel.id)
                                                    }
                                                />
                                                <label htmlFor={`${stepId}-channel-${channel.id}`}>
                                                    {channel.name}
                                                    {channel.isActive ? '' : ' (not active)'}
                                                </label>
                                            </div>
                                        ))
                                    )}
                                    {channelError && (
                                        <p
                                            id={`${stepId}-channels-error`}
                                            role="alert"
                                            className="mt-1 text-red-300"
                                        >
                                            {channelError}
                                        </p>
                                    )}
                                </fieldset>
                                {form.data.steps.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() => removeStep(index)}
                                        className="mt-3 text-sm text-red-200 underline"
                                    >
                                        Remove step {index + 1}
                                    </button>
                                )}
                            </fieldset>
                        </li>
                    );
                })}
            </ol>

            <div className="flex flex-wrap gap-3">
                {form.data.steps.length < limits.stepsPerPolicy && (
                    <button
                        type="button"
                        onClick={addStep}
                        className="rounded border border-slate-600 px-3 py-2"
                    >
                        Add step
                    </button>
                )}
                <button
                    disabled={form.processing}
                    className="rounded bg-emerald-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    {form.processing ? 'Saving…' : submitLabel}
                </button>
            </div>
            {errors.steps && (
                <p role="alert" className="text-red-300">
                    {errors.steps}
                </p>
            )}
        </form>
    );
}
