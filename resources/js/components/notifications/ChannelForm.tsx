/** Create a notification channel or change one, without ever receiving its stored secrets. */
import Button from '../ui/Button';
import SelectField from '../ui/SelectField';
import InputField from '../ui/InputField';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { channelFields } from './labels';
import type { ChannelTypeOption } from './types';

/** Submitted values; `config` keys follow the family's fields. */
interface ChannelFields {
    /** Channel family; ignored by the server on edit. */
    type: string;
    /** Label. */
    name: string;
    /** Configuration fields; a blank secret keeps the stored one on edit. */
    config: Record<string, string>;
}

/** Form inputs. */
interface ChannelFormProps {
    /** Element identity prefix, unique per form on the page. */
    idPrefix: string;
    /** Endpoint receiving the submission. */
    action: string;
    /** `post` creates; `patch` changes an existing channel. */
    method: 'post' | 'patch';
    /** Families offered on creation; on edit the single fixed family. */
    types: ChannelTypeOption[];
    /** Initial label. */
    name: string;
    /** Non-secret values to prefill on edit. */
    editable: Record<string, string>;
    /** Longest accepted label. */
    nameMaxLength: number;
    /** Submit button text. */
    submitLabel: string;
    /** Called after a successful submission, such as closing an inline editor. */
    onSaved?: () => void;
}

/**
 * Secret inputs start blank and say so; configuration errors arrive per field as `config.<name>`, so each
 * message is announced next to its input.
 */
export default function ChannelForm({
    idPrefix,
    action,
    method,
    types,
    name,
    editable,
    nameMaxLength,
    submitLabel,
    onSaved,
}: ChannelFormProps) {
    const form = useForm<ChannelFields>({
        type: types[0]?.value ?? 'email',
        name,
        config: { ...editable },
    });
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const errors = form.errors as Record<string, string | undefined>;
    const selected = types.find((option) => option.value === form.data.type);
    const editing = method === 'patch';

    /** Submit once; only confirmed acceptance may clear values or close this editor. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked) return;
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
            <fieldset disabled={blocked} className="min-w-0 grid gap-4 md:grid-cols-2">
                {!editing && (
                    <SelectField
                        label="Channel type"
                        id={`${idPrefix}-type`}
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData({ ...form.data, type: event.target.value, config: {} })
                        }
                    >
                        {types.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </SelectField>
                )}
                <InputField
                    label="Name"
                    error={errors.name}
                    id={`${idPrefix}-name`}
                    required
                    maxLength={nameMaxLength}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
                {channelFields(form.data.type).map((field) => {
                    const id = `${idPrefix}-${field.name}`;
                    const error = errors[`config.${field.name}`];
                    const secret = selected?.secretFields.includes(field.name) ?? false;

                    return (
                        <InputField
                            label={field.label}
                            error={error}
                            hint={
                                <>
                                    {editing && secret
                                        ? 'Leave empty to keep the stored value. '
                                        : ''}{' '}
                                    {field.hint}
                                </>
                            }
                            key={field.name}
                            id={id}
                            type={field.input}
                            autoComplete="off"
                            value={form.data.config[field.name] ?? ''}
                            onChange={(event) =>
                                form.setData('config', {
                                    ...form.data.config,
                                    [field.name]: event.target.value,
                                })
                            }
                        />
                    );
                })}
                <div className="md:col-span-2">
                    {editing && (
                        <p className="mb-3 text-sm text-[var(--color-ui-muted)]">
                            Changing the destination or a credential pauses the channel until it is
                            verified again.
                        </p>
                    )}
                    <Button disabled={form.processing} variant="primary" type="submit">
                        {form.processing ? 'Saving…' : submitLabel}
                    </Button>
                </div>
            </fieldset>
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={action.split('/notification-channels')[0] + '/notifications'}
                success="Configuration saved"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}
