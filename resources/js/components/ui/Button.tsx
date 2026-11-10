/** Provide native action buttons with consistent focus, pending and disabled states. */
import type { ComponentPropsWithRef } from 'react';

/** Native button attributes plus explicit presentation and request feedback. */
interface ButtonProps extends ComponentPropsWithRef<'button'> {
    /** Visual priority; destructive actions use danger and still need caller-owned confirmation. */
    variant?: 'primary' | 'secondary' | 'danger';
    /** Disable activation while the caller is awaiting a response; never starts or retries work. */
    busy?: boolean;
    /** Visible pending action label; defaults to Working…. */
    busyLabel?: string;
}

/**
 * Render an action rather than navigation; default to a non-submitting button inside forms.
 * @param props Native attributes, action content and caller-controlled pending state.
 * @returns A native button that cannot be activated while disabled or busy.
 */
export default function Button({
    variant = 'primary',
    busy = false,
    busyLabel = 'Working…',
    type = 'button',
    disabled,
    className = '',
    children,
    ...props
}: ButtonProps) {
    return (
        <button
            {...props}
            type={type}
            disabled={disabled || busy}
            aria-busy={busy || undefined}
            data-variant={variant}
            className={`ui-button ${className}`.trim()}
        >
            {busy ? busyLabel : children}
        </button>
    );
}
