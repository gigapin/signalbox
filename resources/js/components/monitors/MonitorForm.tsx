/** Shared create/edit form with type-specific sections and explicit replacement of stored secrets. */
import { useFormProtection } from '../app/NavigationGuard';
import Button from '../ui/Button';
import MutationFeedback from './MutationFeedback';
import { useMonitorMutation } from '../../hooks/useMonitorMutation';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import {
    buildPayload,
    HTTP_TYPES,
    secretsRequired,
    type HeaderRow,
    type MonitorFormData,
} from './formData';
import { regionLabel, typeLabel } from './labels';
import { intervalValidationError } from './intervalValidation';
import type { MonitorConfigurationView, MonitorOptions, MonitorUsage } from './types';

/** Form inputs for one create or edit screen. */
interface MonitorFormProps {
    /** Starting state from initialFormData or editFormData. */
    initial: MonitorFormData;
    /** Persisted type when editing, or null when creating. */
    originalType: string | null;
    /** Stored safe configuration when editing, used to describe kept secrets. */
    stored: MonitorConfigurationView | null;
    /** Endpoint receiving the submission. */
    action: string;
    /** POST for creation, PATCH for partial updates. */
    method: 'post' | 'patch';
    /** Enum values for selects and checkboxes. */
    options: MonitorOptions;
    /** Plan quota, used to explain the minimum interval. */
    usage: MonitorUsage;
    /** Operational cadence ceiling in seconds, supplied by the server configuration. */
    maximumIntervalSeconds: number;
    /** Submit button label. */
    submitLabel: string;
    /** Safe GET destination for reviewing possibly committed changes without losing the draft. */
    reviewUrl: string;
    /** Existing GET destination for explicit cancellation, subject to the departure guard. */
    cancelUrl: string;
}

/** Inputs for one labelled text-like field. */
interface FieldProps {
    /** Input identity and server error key. */
    name: string;
    /** Visible label. */
    label: string;
    /** Current value. */
    value: string;
    /**
     * @param value New raw input value.
     */
    onChange: (value: string) => void;
    /** Server message for this field, if any. */
    error?: string | undefined;
    /** Optional explanation linked through aria-describedby. */
    hint?: string;
    /** Numeric inputs use a numeric keyboard without client-side coercion. */
    numeric?: boolean;
    /** Whether the browser should require a value. */
    required?: boolean;
    /** Render a multi-line text area instead of a single-line input. */
    multiline?: boolean;
}

/** Inputs for a select bound to server-supplied values. */
interface SelectFieldProps {
    /** Select identity and server error key. */
    name: string;
    /** Visible label. */
    label: string;
    /** Current value. */
    value: string;
    /** Offered values. */
    values: string[];
    /**
     * @param value Selected value.
     */
    onChange: (value: string) => void;
    /** Server message for this field, if any. */
    error?: string | undefined;
    /**
     * @param value Raw option value.
     * @returns Display label; defaults to the raw value.
     */
    format?: (value: string) => string;
}

/** Inputs for a section grouping one type's settings. */
interface SectionProps {
    /** Accessible group name. */
    legend: string;
    /** Section fields. */
    children: ReactNode;
}

/** Form keys holding plain strings, which text inputs and selects may update directly. */
type TextField = {
    [K in keyof MonitorFormData]: MonitorFormData[K] extends string ? K : never;
}[keyof MonitorFormData];

/** Field styling shared by inputs, selects and text areas. */
const INPUT = 'ui-input w-full';

/**
 * @param name Field identity.
 * @param error Server message, if any.
 * @param hint Whether a hint element exists.
 * @returns Space-separated IDs of the descriptions, or undefined when none apply.
 */
function describedBy(name: string, error: string | undefined, hint: boolean): string | undefined {
    const ids = [hint ? `${name}-hint` : '', error ? `${name}-error` : ''].filter(
        (id) => id !== '',
    );

    return ids.length > 0 ? ids.join(' ') : undefined;
}

/** Render one labelled input with its hint and inline error. */
function Field({
    name,
    label,
    value,
    onChange,
    error,
    hint,
    numeric,
    required,
    multiline,
}: FieldProps) {
    const shared = {
        id: name,
        name,
        value,
        required,
        'aria-invalid': Boolean(error),
        'aria-describedby': describedBy(name, error, hint !== undefined),
        className: INPUT,
    };

    return (
        <div>
            <label htmlFor={name} className="ui-label">
                {label}
            </label>
            {multiline ? (
                <textarea {...shared} rows={4} onChange={(event) => onChange(event.target.value)} />
            ) : (
                <input
                    {...shared}
                    type="text"
                    inputMode={numeric ? 'numeric' : undefined}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
            {hint !== undefined && (
                <p id={`${name}-hint`} className="ui-help">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${name}-error`} role="alert" className="ui-field-error">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Render one labelled select with its inline error. */
function SelectField({ name, label, value, values, onChange, error, format }: SelectFieldProps) {
    return (
        <div>
            <label htmlFor={name} className="ui-label">
                {label}
            </label>
            <select
                id={name}
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? `${name}-error` : undefined}
                className={INPUT}
            >
                {values.map((option) => (
                    <option key={option} value={option}>
                        {format ? format(option) : option}
                    </option>
                ))}
            </select>
            {error && (
                <p id={`${name}-error`} role="alert" className="ui-field-error">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Group one type's settings under a visible legend. */
function Section({ legend, children }: SectionProps) {
    return (
        <fieldset className="ui-panel space-y-4">
            <legend className="ui-section-title px-1">{legend}</legend>
            {children}
        </fieldset>
    );
}

/**
 * @param errors All server errors.
 * @param prefix Field name whose own and nested (`prefix.*`) errors are collected.
 * @returns Messages in server order.
 */
function nestedErrors(errors: Record<string, string>, prefix: string): string[] {
    return Object.entries(errors)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message);
}

/** Fields rendered with inline errors; other error keys are listed at the top of the form. */
const INLINE_FIELDS = [
    'name',
    'type',
    'interval_seconds',
    'timeout_ms',
    'confirmation_threshold',
    'url',
    'method',
    'body',
    'expected_status',
    'expected_keyword',
    'keyword_mode',
    'tls_host',
    'tls_port',
    'tls_expiry_warning_days',
    'dns_name',
    'dns_record_type',
    'dns_resolver',
    'heartbeat_grace_seconds',
];

/** Prefixes whose nested errors are rendered beside their editors. */
const NESTED_FIELDS = ['regions', 'headers', 'dns_expected_values'];

/**
 * Submit only relevant fields through Inertia. In edit mode URL, headers and body are omitted unless the
 * user ticks the matching replace option, so the server keeps the stored values it never sent us.
 */
export default function MonitorForm({
    initial,
    originalType,
    stored,
    action,
    method,
    options,
    usage,
    maximumIntervalSeconds,
    submitLabel,
    reviewUrl,
    cancelUrl,
}: MonitorFormProps) {
    const form = useForm<MonitorFormData>(initial);
    useFormProtection(form);
    const mutation = useMonitorMutation();
    const formElement = useRef<HTMLFormElement>(null);
    const [serverFocusAttempt, setServerFocusAttempt] = useState(0);
    const data = form.data;
    const errors = form.errors as Record<string, string>;
    const isHttp = HTTP_TYPES.includes(data.type);
    const mustSendSecrets = secretsRequired(data, originalType);
    const storedHttp = stored !== null && HTTP_TYPES.includes(stored.type) && !mustSendSecrets;
    const intervalError = intervalValidationError(
        data.interval_seconds,
        usage.minimumIntervalSeconds,
        maximumIntervalSeconds,
        originalType === null ? null : initial.interval_seconds,
    );

    const general = Object.entries(errors).filter(
        ([key]) =>
            !INLINE_FIELDS.includes(key) &&
            !NESTED_FIELDS.some((prefix) => key === prefix || key.startsWith(`${prefix}.`)),
    );

    // Focus only after a server refusal commits; live edits must not steal focus on each keystroke.
    useEffect(() => {
        if (serverFocusAttempt === 0) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            formElement.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"], [data-form-alert]')
                ?.focus();
        });

        return () => window.cancelAnimationFrame(frame);
    }, [serverFocusAttempt]);

    /**
     * @param field Form key to update.
     * @returns Change handler for a string field.
     */
    function bind(field: TextField) {
        return (value: string) => form.setData(field, value);
    }

    /** @param event Submission from either the button or Enter; invalid cadence stays entirely local. */
    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();

        if (intervalError !== undefined) {
            formElement.current?.querySelector<HTMLInputElement>('#interval_seconds')?.focus();

            return;
        }

        form.transform((current) => buildPayload(current, originalType));

        mutation.run(
            action,
            (visit) => {
                if (method === 'post') {
                    form.post(action, visit);
                } else {
                    form.patch(action, visit);
                }
            },
            () => setServerFocusAttempt((attempt) => attempt + 1),
        );
    }

    /**
     * @param region Region value to toggle.
     * @param checked Whether it should be selected.
     */
    function toggleRegion(region: string, checked: boolean) {
        const next = checked
            ? [...data.regions, region]
            : data.regions.filter((value) => value !== region);
        form.setData(
            'regions',
            options.regions.filter((value) => next.includes(value)),
        );
    }

    /**
     * @param index Header row to change.
     * @param row Replacement row.
     */
    function setHeader(index: number, row: HeaderRow) {
        form.setData(
            'headers',
            data.headers.map((current, position) => (position === index ? row : current)),
        );
    }

    /**
     * @param index DNS value row to change.
     * @param value Replacement value.
     */
    function setDnsValue(index: number, value: string) {
        form.setData(
            'dns_expected_values',
            data.dns_expected_values.map((current, position) =>
                position === index ? value : current,
            ),
        );
    }

    const showUrl = isHttp && (mustSendSecrets || data.replace_url);
    const showHeaders = isHttp && (mustSendSecrets || data.replace_headers);
    const showBody = isHttp && (mustSendSecrets || data.replace_body);

    return (
        <form
            ref={formElement}
            onSubmit={submit}
            className="ui-monitor-form"
            noValidate
            aria-busy={mutation.phase === 'pending'}
        >
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={reviewUrl}
                success="Monitor saved"
                onReviewed={mutation.acknowledge}
            />
            <fieldset
                className="ui-monitor-form-fields"
                disabled={mutation.phase === 'pending' || form.processing}
            >
                <legend className="sr-only">Monitor configuration</legend>
                {general.length > 0 && (
                    <div
                        role="alert"
                        tabIndex={-1}
                        data-form-alert
                        className="rounded border border-red-800 p-3 text-red-300"
                    >
                        {general.map(([key, message]) => (
                            <p key={key}>{message}</p>
                        ))}
                    </div>
                )}

                <Section legend="General">
                    <Field
                        name="name"
                        label="Name"
                        value={data.name}
                        onChange={bind('name')}
                        error={errors.name}
                        required
                    />
                    <SelectField
                        name="type"
                        label="Type"
                        value={data.type}
                        values={options.types}
                        format={typeLabel}
                        onChange={bind('type')}
                        error={errors.type}
                    />
                    {originalType !== null && data.type !== originalType && (
                        <p className="text-sm text-amber-200">
                            Changing the type discards the previous type&apos;s settings; enter the
                            complete new configuration.
                        </p>
                    )}
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field
                            name="interval_seconds"
                            label="Interval (seconds)"
                            value={data.interval_seconds}
                            onChange={(value) => {
                                form.clearErrors('interval_seconds');
                                form.setData('interval_seconds', value);
                            }}
                            error={intervalError ?? errors.interval_seconds}
                            hint={`Your plan allows at least ${usage.minimumIntervalSeconds} seconds.`}
                            numeric
                            required
                        />
                        <Field
                            name="timeout_ms"
                            label="Timeout (milliseconds)"
                            value={data.timeout_ms}
                            onChange={bind('timeout_ms')}
                            error={errors.timeout_ms}
                            numeric
                            required
                        />
                        <Field
                            name="confirmation_threshold"
                            label="Failures before alerting"
                            value={data.confirmation_threshold}
                            onChange={bind('confirmation_threshold')}
                            error={errors.confirmation_threshold}
                            numeric
                            required
                        />
                    </div>
                    <fieldset aria-describedby={errors.regions ? 'regions-error' : undefined}>
                        <legend className="mb-2 text-sm font-medium">Regions</legend>
                        <div className="flex flex-wrap gap-4">
                            {options.regions.map((region) => (
                                <label key={region} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="regions"
                                        value={region}
                                        checked={data.regions.includes(region)}
                                        onChange={(event) =>
                                            toggleRegion(region, event.target.checked)
                                        }
                                    />
                                    {regionLabel(region)}
                                </label>
                            ))}
                        </div>
                        {nestedErrors(errors, 'regions').map((message) => (
                            <p
                                key={message}
                                id="regions-error"
                                role="alert"
                                className="ui-field-error"
                            >
                                {message}
                            </p>
                        ))}
                    </fieldset>
                </Section>

                {isHttp && (
                    <Section legend={data.type === 'keyword' ? 'Keyword request' : 'HTTP request'}>
                        {storedHttp && (
                            <p className="text-sm text-slate-300">
                                Stored URL: <code>{stored.url}</code> (query values hidden).
                            </p>
                        )}
                        {!mustSendSecrets && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.replace_url}
                                    onChange={(event) =>
                                        form.setData('replace_url', event.target.checked)
                                    }
                                />
                                Replace URL
                            </label>
                        )}
                        {showUrl && (
                            <Field
                                name="url"
                                label="URL"
                                value={data.url}
                                onChange={bind('url')}
                                error={errors.url}
                                required
                            />
                        )}
                        <div className="grid gap-4 md:grid-cols-2">
                            <SelectField
                                name="method"
                                label="Method"
                                value={data.method}
                                values={options.methods}
                                onChange={bind('method')}
                                error={errors.method}
                            />
                            <Field
                                name="expected_status"
                                label="Expected status"
                                value={data.expected_status}
                                onChange={bind('expected_status')}
                                error={errors.expected_status}
                                numeric
                                required
                            />
                        </div>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={data.follow_redirects}
                                onChange={(event) =>
                                    form.setData('follow_redirects', event.target.checked)
                                }
                            />
                            Follow redirects
                        </label>

                        {storedHttp && (
                            <p className="text-sm text-slate-300">
                                Stored headers:{' '}
                                {stored.header_names && stored.header_names.length > 0
                                    ? `${stored.header_names.join(', ')} (values hidden)`
                                    : 'none'}
                                .
                            </p>
                        )}
                        {!mustSendSecrets && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.replace_headers}
                                    onChange={(event) =>
                                        form.setData('replace_headers', event.target.checked)
                                    }
                                />
                                Replace all headers
                            </label>
                        )}
                        {showHeaders && (
                            <fieldset>
                                <legend className="mb-2 text-sm font-medium">Headers</legend>
                                {data.headers.length === 0 && (
                                    <p className="text-sm text-slate-400">No headers.</p>
                                )}
                                {data.headers.map((row, index) => (
                                    <div
                                        key={index}
                                        className="mb-2 grid gap-2 md:grid-cols-[1fr_2fr_auto]"
                                    >
                                        <input
                                            aria-label={`Header ${index + 1} name`}
                                            value={row.name}
                                            onChange={(event) =>
                                                setHeader(index, {
                                                    ...row,
                                                    name: event.target.value,
                                                })
                                            }
                                            className={INPUT}
                                        />
                                        <input
                                            aria-label={`Header ${index + 1} value`}
                                            value={row.value}
                                            onChange={(event) =>
                                                setHeader(index, {
                                                    ...row,
                                                    value: event.target.value,
                                                })
                                            }
                                            className={INPUT}
                                        />
                                        <button
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'headers',
                                                    data.headers.filter(
                                                        (_, position) => position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove header {index + 1}
                                        </button>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('headers', [
                                            ...data.headers,
                                            { name: '', value: '' },
                                        ])
                                    }
                                    className="ui-button"
                                    data-variant="secondary"
                                >
                                    Add header
                                </button>
                                {nestedErrors(errors, 'headers').map((message) => (
                                    <p key={message} role="alert" className="ui-field-error">
                                        {message}
                                    </p>
                                ))}
                            </fieldset>
                        )}

                        {storedHttp && (
                            <p className="text-sm text-slate-300">
                                Stored body:{' '}
                                {stored.body?.present
                                    ? `${stored.body.bytes} bytes (content hidden)`
                                    : 'none'}
                                .
                            </p>
                        )}
                        {!mustSendSecrets && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.replace_body}
                                    onChange={(event) =>
                                        form.setData('replace_body', event.target.checked)
                                    }
                                />
                                Replace or clear body
                            </label>
                        )}
                        {showBody && (
                            <Field
                                name="body"
                                label="Body"
                                value={data.body}
                                onChange={bind('body')}
                                error={errors.body}
                                hint="Leave empty to send no body."
                                multiline
                            />
                        )}

                        {data.type === 'keyword' && (
                            <div className="grid gap-4 md:grid-cols-2">
                                <Field
                                    name="expected_keyword"
                                    label="Keyword"
                                    value={data.expected_keyword}
                                    onChange={bind('expected_keyword')}
                                    error={errors.expected_keyword}
                                    hint="Matched exactly, including spaces and case."
                                />
                                <SelectField
                                    name="keyword_mode"
                                    label="Keyword must be"
                                    value={data.keyword_mode}
                                    values={options.keywordModes}
                                    onChange={bind('keyword_mode')}
                                    error={errors.keyword_mode}
                                />
                            </div>
                        )}
                    </Section>
                )}

                {data.type === 'tls' && (
                    <Section legend="TLS certificate">
                        <Field
                            name="tls_host"
                            label="Host"
                            value={data.tls_host}
                            onChange={bind('tls_host')}
                            error={errors.tls_host}
                            required
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field
                                name="tls_port"
                                label="Port"
                                value={data.tls_port}
                                onChange={bind('tls_port')}
                                error={errors.tls_port}
                                numeric
                                required
                            />
                            <Field
                                name="tls_expiry_warning_days"
                                label="Expiry warning (days)"
                                value={data.tls_expiry_warning_days}
                                onChange={bind('tls_expiry_warning_days')}
                                error={errors.tls_expiry_warning_days}
                                hint="Optional. Leave empty for no early warning."
                                numeric
                            />
                        </div>
                    </Section>
                )}

                {data.type === 'dns' && (
                    <Section legend="DNS record">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field
                                name="dns_name"
                                label="Query name"
                                value={data.dns_name}
                                onChange={bind('dns_name')}
                                error={errors.dns_name}
                                required
                            />
                            <SelectField
                                name="dns_record_type"
                                label="Record type"
                                value={data.dns_record_type}
                                values={options.dnsRecordTypes}
                                onChange={bind('dns_record_type')}
                                error={errors.dns_record_type}
                            />
                        </div>
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">Expected values</legend>
                            {data.dns_expected_values.map((value, index) => (
                                <div key={index} className="mb-2 flex gap-2">
                                    <input
                                        aria-label={`Expected value ${index + 1}`}
                                        value={value}
                                        onChange={(event) => setDnsValue(index, event.target.value)}
                                        className={INPUT}
                                    />
                                    {data.dns_expected_values.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'dns_expected_values',
                                                    data.dns_expected_values.filter(
                                                        (_, position) => position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove value {index + 1}
                                        </button>
                                    )}
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() =>
                                    form.setData('dns_expected_values', [
                                        ...data.dns_expected_values,
                                        '',
                                    ])
                                }
                                className="ui-button"
                                data-variant="secondary"
                            >
                                Add value
                            </button>
                            {nestedErrors(errors, 'dns_expected_values').map((message) => (
                                <p key={message} role="alert" className="ui-field-error">
                                    {message}
                                </p>
                            ))}
                        </fieldset>
                        <Field
                            name="dns_resolver"
                            label="Resolver"
                            value={data.dns_resolver}
                            onChange={bind('dns_resolver')}
                            error={errors.dns_resolver}
                            hint="Optional public IP address. Leave empty for the default resolver."
                        />
                    </Section>
                )}

                {data.type === 'heartbeat' && (
                    <Section legend="Heartbeat">
                        <Field
                            name="heartbeat_grace_seconds"
                            label="Grace period (seconds)"
                            value={data.heartbeat_grace_seconds}
                            onChange={bind('heartbeat_grace_seconds')}
                            error={errors.heartbeat_grace_seconds}
                            hint="Extra time allowed after each interval before the heartbeat counts as missed."
                            numeric
                            required
                        />
                    </Section>
                )}
            </fieldset>
            <div className="ui-actions">
                <Button
                    type="submit"
                    busy={mutation.phase === 'pending' || form.processing}
                    busyLabel="Saving…"
                    disabled={mutation.locked}
                >
                    {submitLabel}
                </Button>
                <Link className="ui-button" data-variant="secondary" href={cancelUrl}>
                    Cancel
                </Link>
            </div>
        </form>
    );
}
