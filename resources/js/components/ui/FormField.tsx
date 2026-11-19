/** Own the shared label, help, validation announcement and accessible identity of a form control. */
import { RequiredIndicator } from './FieldRequirements';
import { useId, type AriaAttributes, type ReactNode } from 'react';

/** Presentation shared by typed field wrappers; domain state stays with the caller. */
export interface FieldPresentation {
    /** Visible control name, with any domain-specific units or required wording supplied by the caller. */
    label: ReactNode;
    /** Optional explanatory content associated with the control. */
    hint?: ReactNode;
    /** Current validation message; empty and absent messages mean no field error. */
    error?: string | undefined;
    /** Layout constraints of the containing field, independent of native control classes. */
    wrapperClassName?: string | undefined;
    /** Align compact labels beside their controls when space allows. */
    layout?: 'stacked' | 'inline' | undefined;
    /** Keep a programmatic label while omitting its visual space in compact editors. */
    labelHidden?: boolean | undefined;
}

/** Attributes applied to the one native control rendered by a field wrapper. */
export interface FieldBinding {
    /** Explicit or generated stable control identity. */
    id: string;
    /** External instructions followed by current help and validation references. */
    'aria-describedby': string | undefined;
    /** A field error takes precedence over caller-supplied invalid state. */
    'aria-invalid': AriaAttributes['aria-invalid'];
}

/** Shared metadata plus the native-control render boundary. */
interface FormFieldProps extends FieldPresentation {
    /** Native requirement forwarded by the field wrapper; marker policy comes from the form context. */
    required?: boolean | undefined;
    /** Prefer a caller identity where labels and external links already reference it. */
    id?: string | undefined;
    /** Existing caller descriptions must not be discarded. */
    describedBy?: string | undefined;
    /** Native validation state when no explicit message is present. */
    invalid?: AriaAttributes['aria-invalid'];
    /** Choice placement is reserved for checkbox/radio controls. */
    choice?: boolean | undefined;
    /** Render exactly one control with the shared accessibility attributes. */
    children: (binding: FieldBinding) => ReactNode;
}

/**
 * @param props Label, help, error and a typed control renderer; no domain effects are performed.
 * @returns A content-sized field that resists grid stretching, with stable IDs and polite validation feedback.
 */
export default function FormField({
    id,
    required = false,
    label,
    hint,
    error,
    wrapperClassName = '',
    labelHidden = false,
    describedBy,
    invalid,
    choice = false,
    layout = 'stacked',
    children,
}: FormFieldProps) {
    const generated = useId();
    const controlId = id ?? generated;
    const description = [
        describedBy,
        hint ? `${controlId}-hint` : '',
        error ? `${controlId}-error` : '',
    ]
        .filter(Boolean)
        .join(' ');
    const fieldClass = choice ? 'ui-choice-field' : 'ui-field';
    const layoutClass = !choice && layout === 'inline' ? ' ui-field-inline' : '';

    return (
        <div className={`${fieldClass}${layoutClass} ${wrapperClassName}`.trim()}>
            <label htmlFor={controlId} className={`ui-label${labelHidden ? ' sr-only' : ''}`}>
                {label}
                <RequiredIndicator required={required} />
            </label>
            {children({
                id: controlId,
                'aria-describedby': description || undefined,
                'aria-invalid': error ? true : invalid,
            })}
            {hint && (
                <p id={`${controlId}-hint`} className="ui-help">
                    {hint}
                </p>
            )}
            <p
                id={`${controlId}-error`}
                className="ui-field-error"
                role={error ? 'alert' : undefined}
                aria-live="polite"
                aria-atomic="true"
            >
                {error || null}
            </p>
        </div>
    );
}
