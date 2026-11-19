/** Provide native action buttons with consistent focus, pending and disabled states. */
import LoadingSpinner from './LoadingSpinner';
import type { ComponentPropsWithRef, ReactNode } from 'react';

/** Native button attributes plus explicit presentation and request feedback. */
interface ButtonProps extends ComponentPropsWithRef<'button'> {
    /** Visual priority; destructive actions use danger and still need caller-owned confirmation. */
    variant?: 'primary' | 'secondary' | 'danger';
    /** Disable activation while the caller is awaiting a response; never starts or retries work. */
    busy?: boolean;
    /** Visible pending action label; defaults to Working…. */
    busyLabel?: string;
    /** Indicator mode preserves the label and swaps a fixed-size icon slot for the loading spinner. */
    busyMode?: 'label' | 'indicator';
    /** Optional decorative leading icon; ignored while label-only busy feedback is shown. */
    icon?: ReactNode;
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
    busyMode = 'label',
    icon,
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
            {busy && busyMode === 'label' ? (
                busyLabel
            ) : (
                <>
                    {(icon || busyMode === 'indicator') && (
                        <span className="ui-button-icon" aria-hidden="true">
                            {busy && busyMode === 'indicator' ? <LoadingSpinner /> : icon}
                        </span>
                    )}
                    {children}
                </>
            )}
        </button>
    );
}
