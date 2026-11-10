/** Associate a native input with its visible label, optional help and validation feedback. */
import { useId, type ComponentPropsWithRef } from 'react';

/** Native input attributes with required human-readable naming and optional validation. */
interface InputFieldProps extends Omit<ComponentPropsWithRef<'input'>, 'children'> {
    /** Visible label; placeholders never replace this name. */
    label: string;
    /** Safe instructions such as units and accepted ranges. */
    hint?: string;
    /** Caller-owned validation error; an empty string means no error. */
    error?: string;
}

/**
 * Preserve the native input/ref contract without owning or persisting form values.
 * @param props Label, hints, validation and native input behavior.
 * @returns A labelled input whose description includes help, errors and caller-supplied references.
 */
export default function InputField({
    label,
    hint,
    error,
    id,
    className = '',
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    ...props
}: InputFieldProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const description = [
        describedBy,
        hint ? `${inputId}-hint` : '',
        error ? `${inputId}-error` : '',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div className="ui-field">
            <label htmlFor={inputId} className="ui-label">
                {label}
                {props.required ? ' (required)' : ''}
            </label>
            {hint && (
                <p id={`${inputId}-hint`} className="ui-help">
                    {hint}
                </p>
            )}
            <input
                {...props}
                id={inputId}
                aria-describedby={description || undefined}
                aria-invalid={error ? true : invalid}
                className={`ui-input ${className}`.trim()}
            />
            <p
                id={`${inputId}-error`}
                className="ui-field-error"
                aria-live="polite"
                aria-atomic="true"
            >
                {error || null}
            </p>
        </div>
    );
}
