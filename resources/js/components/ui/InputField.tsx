/** Compose a native input with shared field presentation without owning its value or validation rules. */
import type { ComponentPropsWithRef } from 'react';
import FormField, { type FieldPresentation } from './FormField';

/** Native input/ref contract plus the shared field metadata. */
interface InputFieldProps
    extends Omit<ComponentPropsWithRef<'input'>, 'children'>, FieldPresentation {}

/**
 * @param props Native input attributes/ref, supplied label, optional help and current error.
 * @returns A labelled input preserving controlled/uncontrolled values and native events.
 */
export default function InputField({
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
}: InputFieldProps) {
    return (
        <FormField
            id={id}
            label={label}
            required={props.required}
            hint={hint}
            error={error}
            wrapperClassName={wrapperClassName}
            labelHidden={labelHidden}
            layout={layout}
            describedBy={describedBy}
            invalid={invalid}
        >
            {(binding) => (
                <input {...props} {...binding} className={`ui-input ${className}`.trim()} />
            )}
        </FormField>
    );
}
