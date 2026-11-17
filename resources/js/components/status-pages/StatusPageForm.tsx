/** Create or change a status page: address, texts, appearance, visibility, password and ordered components. */
import FormField from '../ui/FormField';
import InputField from '../ui/InputField';
import TextareaField from '../ui/TextareaField';
import SelectField from '../ui/SelectField';
import ChoiceField from '../ui/ChoiceField';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import { useForm } from '@inertiajs/react';
import { useOwnedMutation } from '../../hooks/useOwnedMutation';
import MutationFeedback from '../MutationFeedback';
import type { FormEvent } from 'react';
import type { ManagedStatusPage, SelectableMonitor, StatusPageLimits, ThemeChoices } from './types';

/** One editable component. */
interface ComponentFields {
    /** Public label. */
    name: string;
    /** Selected monitor identities. */
    monitor_ids: number[];
}

/** Submitted values. */
interface PageFields {
    /** Public address segment. */
    slug: string;
    /** Page heading. */
    title: string;
    /** Optional introduction. */
    description: string;
    /** Color scheme. */
    theme_mode: string;
    /** Accent preset. */
    theme_accent: string;
    /** Whether the public address answers. */
    is_public: boolean;
    /** New password; empty keeps the current state. */
    password: string;
    /** Whether to remove the current password. */
    remove_password: boolean;
    /** Components in display order. */
    components: ComponentFields[];
}

/** Form inputs. */
interface StatusPageFormProps {
    /** Element identity prefix, unique per form on the page. */
    idPrefix: string;
    /** Endpoint receiving the submission. */
    action: string;
    /** `post` creates; `patch` changes an existing page. */
    method: 'post' | 'patch';
    /** Page being edited, or null on creation. */
    page: ManagedStatusPage | null;
    /** Monitors of the team. */
    monitors: SelectableMonitor[];
    /** Appearance choices. */
    themeChoices: ThemeChoices;
    /** Form bounds. */
    limits: StatusPageLimits;
    /** Submit button text. */
    submitLabel: string;
    /** Called after a successful submission. */
    onSaved?: () => void;
}

/**
 * @param page Page being edited, or null.
 * @returns Initial values: the page's own, or a draft with the default appearance and no components.
 */
function initialFields(page: ManagedStatusPage | null): PageFields {
    return {
        slug: page?.slug ?? '',
        title: page?.title ?? '',
        description: page?.description ?? '',
        theme_mode: page?.theme.mode ?? 'system',
        theme_accent: page?.theme.accent ?? 'emerald',
        is_public: page?.isPublic ?? false,
        password: '',
        remove_password: false,
        components: (page?.components ?? []).map((component) => ({
            name: component.name,
            monitor_ids: component.monitors.map((monitor) => monitor.id),
        })),
    };
}

/**
 * A monitor already chosen in one component is disabled in the others, as the server allows it once per page.
 * Order changes use buttons so they work from the keyboard.
 */
export default function StatusPageForm({
    idPrefix,
    action,
    method,
    page,
    monitors,
    themeChoices,
    limits,
    submitLabel,
    onSaved,
}: StatusPageFormProps) {
    const form = useForm<PageFields>(initialFields(page));
    useFormProtection(form);
    const mutation = useOwnedMutation();
    const guard = useNavigationGuard();
    const blocked = mutation.locked || guard.busy;
    const errors = form.errors as Record<string, string | undefined>;

    /**
     * @param index Component position.
     * @param change Fields replaced in that component.
     */
    function updateComponent(index: number, change: Partial<ComponentFields>) {
        form.setData(
            'components',
            form.data.components.map((component, position) =>
                position === index ? { ...component, ...change } : component,
            ),
        );
    }

    /**
     * @param index Component position.
     * @param monitorId Monitor toggled in that component.
     */
    function toggleMonitor(index: number, monitorId: number) {
        const component = form.data.components[index];

        if (component === undefined) {
            return;
        }

        const selected = component.monitor_ids.includes(monitorId)
            ? component.monitor_ids.filter((id) => id !== monitorId)
            : [...component.monitor_ids, monitorId];

        updateComponent(index, { monitor_ids: selected });
    }

    /**
     * @param index Component position.
     * @param offset `-1` moves it up; `1` moves it down.
     */
    function moveComponent(index: number, offset: -1 | 1) {
        const target = index + offset;
        const moved = form.data.components[index];
        const other = form.data.components[target];

        if (moved === undefined || other === undefined) {
            return;
        }

        const reordered = [...form.data.components];
        reordered[index] = other;
        reordered[target] = moved;
        form.setData('components', reordered);
    }

    function addComponent() {
        form.setData('components', [...form.data.components, { name: '', monitor_ids: [] }]);
    }

    /** @param index Component removed from the page. */
    function removeComponent(index: number) {
        form.setData(
            'components',
            form.data.components.filter((_, position) => position !== index),
        );
    }

    /** Submit once; refused or uncertain writes preserve the complete page/component/password draft. */
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (blocked) return;
        mutation.run(
            action,
            (options) => form.submit(method, action, options),
            undefined,
            () => {
                if (method === 'post') form.reset();
                else form.setData({ ...form.data, password: '', remove_password: false });
                onSaved?.();
            },
        );
    }

    return (
        <form onSubmit={submit} noValidate>
            <fieldset disabled={blocked} className="grid min-w-0 gap-4">
                <InputField
                    label="Title"
                    error={errors.title}
                    id={`${idPrefix}-title`}
                    required
                    maxLength={limits.titleMaxLength}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                />
                <FormField
                    id={`${idPrefix}-slug`}
                    label="Public address"
                    hint="3 to 63 lowercase letters, digits or inner hyphens."
                    error={errors.slug}
                >
                    {(binding) => (
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-[var(--color-ui-muted)]">/status/</span>
                            <input
                                {...binding}
                                className="ui-input"
                                required
                                maxLength={63}
                                value={form.data.slug}
                                onChange={(event) => form.setData('slug', event.target.value)}
                            />
                        </div>
                    )}
                </FormField>
                <TextareaField
                    label="Description (optional)"
                    error={errors.description}
                    id={`${idPrefix}-description`}
                    rows={2}
                    maxLength={limits.descriptionMaxLength}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                />
                <div className="flex flex-wrap gap-4">
                    <SelectField
                        label="Color scheme"
                        id={`${idPrefix}-mode`}
                        value={form.data.theme_mode}
                        onChange={(event) => form.setData('theme_mode', event.target.value)}
                    >
                        {themeChoices.modes.map((mode) => (
                            <option key={mode} value={mode}>
                                {mode}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label="Accent"
                        id={`${idPrefix}-accent`}
                        value={form.data.theme_accent}
                        onChange={(event) => form.setData('theme_accent', event.target.value)}
                    >
                        {themeChoices.accents.map((accent) => (
                            <option key={accent} value={accent}>
                                {accent}
                            </option>
                        ))}
                    </SelectField>
                </div>
                <ChoiceField
                    label="Published: anyone with the address can reach this page"
                    id={`${idPrefix}-public`}
                    type="checkbox"
                    checked={form.data.is_public}
                    onChange={(event) => form.setData('is_public', event.target.checked)}
                />
                <div>
                    <InputField
                        label={page?.hasPassword === true ? 'New password' : 'Password (optional)'}
                        error={errors.password}
                        hint={
                            page?.hasPassword === true
                                ? 'Leave empty to keep the current one. A new password locks out visitors who had entered the old one.'
                                : `At least ${limits.passwordMinLength} characters. Visitors must enter it to read the page.`
                        }
                        id={`${idPrefix}-password`}
                        type="password"
                        autoComplete="new-password"
                        minLength={limits.passwordMinLength}
                        disabled={form.data.remove_password}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    {page?.hasPassword === true && (
                        <ChoiceField
                            label="Remove the password"
                            wrapperClassName="mt-2"
                            id={`${idPrefix}-remove-password`}
                            type="checkbox"
                            checked={form.data.remove_password}
                            onChange={(event) => {
                                form.setData({
                                    ...form.data,
                                    password: '',
                                    remove_password: event.target.checked,
                                });
                            }}
                        />
                    )}
                </div>

                <ol className="grid gap-4" aria-label="Components">
                    {form.data.components.map((component, index) => {
                        const componentId = `${idPrefix}-component-${index}`;
                        const nameError = errors[`components.${index}.name`];
                        const monitorError = errors[`components.${index}.monitor_ids`];
                        const elsewhere = form.data.components
                            .filter((_, position) => position !== index)
                            .flatMap((other) => other.monitor_ids);

                        return (
                            <li key={componentId} className="ui-panel">
                                <fieldset>
                                    <legend className="font-medium">Component {index + 1}</legend>
                                    <InputField
                                        label="Public name"
                                        error={nameError}
                                        id={`${componentId}-name`}
                                        maxLength={limits.componentNameMaxLength}
                                        value={component.name}
                                        onChange={(event) =>
                                            updateComponent(index, { name: event.target.value })
                                        }
                                    />
                                    <fieldset
                                        className="mt-3"
                                        aria-describedby={`${componentId}-monitors-error`}
                                    >
                                        <legend className="text-sm">
                                            Monitors in this component
                                        </legend>
                                        {monitors.length === 0 ? (
                                            <p className="text-sm text-[var(--color-ui-muted)]">
                                                Create a monitor first.
                                            </p>
                                        ) : (
                                            monitors.map((monitor) => (
                                                <ChoiceField
                                                    label={
                                                        <>
                                                            {monitor.name}{' '}
                                                            {elsewhere.includes(monitor.id)
                                                                ? ' (in another component)'
                                                                : ''}
                                                        </>
                                                    }
                                                    key={monitor.id}
                                                    id={`${componentId}-monitor-${monitor.id}`}
                                                    type="checkbox"
                                                    checked={component.monitor_ids.includes(
                                                        monitor.id,
                                                    )}
                                                    disabled={elsewhere.includes(monitor.id)}
                                                    onChange={() =>
                                                        toggleMonitor(index, monitor.id)
                                                    }
                                                />
                                            ))
                                        )}
                                        {monitorError && (
                                            <p
                                                id={`${componentId}-monitors-error`}
                                                role="alert"
                                                className="mt-1 text-red-300"
                                            >
                                                {monitorError}
                                            </p>
                                        )}
                                    </fieldset>
                                    <div className="mt-3 flex flex-wrap gap-3 text-sm">
                                        {index > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => moveComponent(index, -1)}
                                                className="ui-link"
                                            >
                                                Move component {index + 1} up
                                            </button>
                                        )}
                                        {index < form.data.components.length - 1 && (
                                            <button
                                                type="button"
                                                onClick={() => moveComponent(index, 1)}
                                                className="ui-link"
                                            >
                                                Move component {index + 1} down
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => removeComponent(index)}
                                            className="text-red-200 cursor-pointer"
                                        >
                                            Remove component {index + 1}
                                        </button>
                                    </div>
                                </fieldset>
                            </li>
                        );
                    })}
                </ol>

                <div className="flex flex-wrap gap-3">
                    {form.data.components.length < limits.componentsPerPage && (
                        <button
                            type="button"
                            onClick={addComponent}
                            className="ui-button"
                            data-variant="secondary"
                        >
                            Add component
                        </button>
                    )}
                    <button disabled={form.processing} className="ui-button">
                        {form.processing ? 'Saving…' : submitLabel}
                    </button>
                </div>
                {errors.components && (
                    <p role="alert" className="text-red-300">
                        {errors.components}
                    </p>
                )}
            </fieldset>
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={action.split('/status-pages')[0] + '/status-pages'}
                success="Status page saved"
                onReviewed={mutation.acknowledge}
            />
        </form>
    );
}
