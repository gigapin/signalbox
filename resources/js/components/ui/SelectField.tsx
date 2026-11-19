/** Compose the below-field select picker with shared labels, help and validation. */
import type { ComponentProps } from 'react';
import FormField, { type FieldPresentation } from './FormField';
import Select from './Select';

/** Native select props and option children plus common field metadata. */
interface SelectFieldProps extends ComponentProps<typeof Select>, FieldPresentation {}

/**
 * @param props Controlled/native select attributes, domain options and shared field metadata.
 * @returns The shared picker with one consistent accessible field wrapper.
 */
export default function SelectField({
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
    children,
    ...props
}: SelectFieldProps) {
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
                <Select {...props} {...binding} className={`ui-input ${className}`.trim()}>
                    {children}
                </Select>
            )}
        </FormField>
    );
}
