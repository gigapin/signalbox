/** Compose native multiline input with the same field semantics as other form controls. */
import type { ComponentPropsWithRef } from 'react';
import FormField, { type FieldPresentation } from './FormField';

/** Native textarea values/events/ref and shared presentation metadata. */
interface TextareaFieldProps extends ComponentPropsWithRef<'textarea'>, FieldPresentation {}

/**
 * @param props Native textarea attributes/ref and caller-owned field metadata.
 * @returns A labelled textarea preserving its native editing and validation contract.
 */
export default function TextareaField({
    label,
    hint,
    error,
    wrapperClassName,
    labelHidden,
    layout,
    id,
    className = '',
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    ...props
}: TextareaFieldProps) {
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
        >
            {(binding) => (
                <textarea {...props} {...binding} className={`ui-input ${className}`.trim()} />
            )}
        </FormField>
    );
}
