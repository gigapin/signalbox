/** Create or change an escalation policy: ordered delays, each notifying a set of channels. */
import Button from '../ui/Button';
import InputField from '../ui/InputField';
import ChoiceField from '../ui/ChoiceField';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
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
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
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

    /** Submit once; only confirmed acceptance may clear values or close this editor. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked) return;
        form.transform((data) => ({
            name: data.name,
            is_default: data.is_default,
            steps: data.steps.map((step) => ({
                after_minutes: Number(step.after_minutes),
                channel_ids: step.channel_ids,
            })),
        }));
        mutation.run(
            action,
            (options) => form.submit(method, action, options),
            undefined,
            () => {
                if (method === 'post') form.reset();
                onSaved?.();
            },
        );
    }

    return (
        <form onSubmit={submit} noValidate>
            <fieldset disabled={blocked} className="min-w-0 grid gap-4">
                <InputField
                    label="Policy name"
                    error={errors.name}
                    id={`${idPrefix}-name`}
                    required
                    maxLength={limits.nameMaxLength}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
                <ChoiceField
                    label="Team default: used by monitors that do not choose another policy"
                    id={`${idPrefix}-default`}
                    type="checkbox"
                    checked={form.data.is_default}
                    onChange={(event) => form.setData('is_default', event.target.checked)}
                />

                <ol className="grid gap-4" aria-label="Escalation steps">
                    {form.data.steps.map((step, index) => {
                        const stepId = `${idPrefix}-step-${index}`;
                        const delayError = errors[`steps.${index}.after_minutes`];
                        const channelError = errors[`steps.${index}.channel_ids`];

                        return (
                            <li key={stepId} className="ui-panel">
                                <fieldset>
                                    <legend className="font-medium">Step {index + 1}</legend>
                                    <InputField
                                        label="Minutes after the incident is confirmed"
                                        error={delayError}
                                        id={`${stepId}-delay`}
                                        type="number"
                                        min={0}
                                        max={limits.maxStepDelayMinutes}
                                        value={step.after_minutes}
                                        onChange={(event) =>
                                            updateStep(index, { after_minutes: event.target.value })
                                        }
                                        className="w-32"
                                        wrapperClassName="mt-2"
                                    />
                                    <fieldset
                                        className="mt-3"
                                        aria-describedby={`${stepId}-channels-error`}
                                    >
                                        <legend className="text-sm">Notify these channels</legend>
                                        {channels.length === 0 ? (
                                            <p className="text-sm text-[var(--color-ui-muted)]">
                                                Create a channel first.
                                            </p>
                                        ) : (
                                            channels.map((channel) => (
                                                <ChoiceField
                                                    label={
                                                        <>
                                                            {channel.name}{' '}
                                                            {channel.isActive
                                                                ? ''
                                                                : ' (not active)'}
                                                        </>
                                                    }
                                                    key={channel.id}
                                                    id={`${stepId}-channel-${channel.id}`}
                                                    type="checkbox"
                                                    checked={step.channel_ids.includes(channel.id)}
                                                    onChange={() =>
                                                        toggleChannel(index, channel.id)
                                                    }
                                                />
                                            ))
                                        )}
                                        {channelError && (
                                            <p
                                                id={`${stepId}-channels-error`}
                                                role="alert"
                                                className="mt-1 text-ui-danger"
                                            >
                                                {channelError}
                                            </p>
                                        )}
                                    </fieldset>
                                    {form.data.steps.length > 1 && (
                                        <Button
                                            type="button"
                                            onClick={() => removeStep(index)}
                                            className="mt-3 text-sm"
                                            variant="danger"
                                        >
                                            Remove step {index + 1}
                                        </Button>
                                    )}
                                </fieldset>
                            </li>
                        );
                    })}
                </ol>

                <div className="flex flex-wrap gap-3">
                    {form.data.steps.length < limits.stepsPerPolicy && (
                        <Button type="button" onClick={addStep} variant="secondary">
                            Add step
                        </Button>
                    )}
                    <Button disabled={form.processing} variant="primary" type="submit">
                        {form.processing ? 'Saving…' : submitLabel}
                    </Button>
                </div>
                {errors.steps && (
                    <p role="alert" className="text-ui-danger">
                        {errors.steps}
                    </p>
                )}
            </fieldset>
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={action.split('/escalation-policies')[0] + '/notifications'}
                success="Configuration saved"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}
