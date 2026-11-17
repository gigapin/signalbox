/** Share labelled checkbox/radio presentation while retaining native checked and keyboard behavior. */
import type { ComponentPropsWithRef } from 'react';
import FormField, { type FieldPresentation } from './FormField';

/** Explicit binary/single-choice control attributes; groups and domain selection rules remain caller-owned. */
interface ChoiceFieldProps
    extends Omit<ComponentPropsWithRef<'input'>, 'children' | 'type'>, FieldPresentation {
    /** Checkbox by default; radio preserves the native name-based group semantics. */
    type?: 'checkbox' | 'radio';
}

/**
 * @param props Native checked/events/ref, label, help and error metadata.
 * @returns An inline labelled choice with shared accessible descriptions and feedback.
 */
export default function ChoiceField({
    label,
    hint,
    error,
    wrapperClassName,
    labelHidden,
    layout,
    id,
    type = 'checkbox',
    className = '',
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    ...props
}: ChoiceFieldProps) {
    return (
        <FormField
            id={id}
            label={label}
            hint={hint}
            error={error}
            wrapperClassName={wrapperClassName}
            labelHidden={labelHidden}
            layout={layout}
            describedBy={describedBy}
            invalid={invalid}
            choice
        >
            {(binding) => (
                <input
                    {...props}
                    {...binding}
                    type={type}
                    className={`ui-choice ${className}`.trim()}
                />
            )}
        </FormField>
    );
}
