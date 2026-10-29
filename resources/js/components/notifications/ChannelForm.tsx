/** Create a notification channel or change one, without ever receiving its stored secrets. */
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

/** Field styling shared by the inputs. */
const INPUT =
    'w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 focus:outline-2 focus:outline-emerald-400';

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
    const errors = form.errors as Record<string, string | undefined>;
    const selected = types.find((option) => option.value === form.data.type);
    const editing = method === 'patch';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.submit(method, action, {
            preserveScroll: true,
            onSuccess: () => {
                if (!editing) {
                    form.reset();
                }

                onSaved?.();
            },
        });
    }

    return (
        <form onSubmit={submit} noValidate className="grid gap-4 md:grid-cols-2">
            {!editing && (
                <div>
                    <label htmlFor={`${idPrefix}-type`} className="mb-2 block text-sm font-medium">
                        Channel type
                    </label>
                    <select
                        id={`${idPrefix}-type`}
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData({ ...form.data, type: event.target.value, config: {} })
                        }
                        className={INPUT}
                    >
                        {types.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div>
                <label htmlFor={`${idPrefix}-name`} className="mb-2 block text-sm font-medium">
                    Name
                </label>
                <input
                    id={`${idPrefix}-name`}
                    required
                    maxLength={nameMaxLength}
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
            {channelFields(form.data.type).map((field) => {
                const id = `${idPrefix}-${field.name}`;
                const error = errors[`config.${field.name}`];
                const secret = selected?.secretFields.includes(field.name) ?? false;

                return (
                    <div key={field.name}>
                        <label htmlFor={id} className="mb-2 block text-sm font-medium">
                            {field.label}
                        </label>
                        <input
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
                            aria-invalid={error !== undefined}
                            aria-describedby={`${id}-hint ${id}-error`}
                            className={INPUT}
                        />
                        <p id={`${id}-hint`} className="mt-1 text-xs text-slate-400">
                            {editing && secret ? 'Leave empty to keep the stored value. ' : ''}
                            {field.hint}
                        </p>
                        {error && (
                            <p id={`${id}-error`} role="alert" className="mt-1 text-red-300">
                                {error}
                            </p>
                        )}
                    </div>
                );
            })}
            <div className="md:col-span-2">
                {editing && (
                    <p className="mb-3 text-sm text-slate-400">
                        Changing the destination or a credential pauses the channel until it is
                        verified again.
                    </p>
                )}
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
