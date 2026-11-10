/** Display caller-owned notices with explicit announcement urgency and no automatic actions. */
import type { ReactNode } from 'react';
import type { Tone } from './Badge';

/** Notice content and optional live announcement behavior. */
interface AlertProps {
    /** Visible heading describing the notice. */
    title: string;
    /** Safe explanation and optional caller-owned controls; never raw exceptions or secrets. */
    children: ReactNode;
    /** Semantic appearance independent of announcement urgency. */
    tone?: Tone;
    /** Static by default; polite for acknowledgements, assertive for newly raised blocking errors. */
    announce?: 'off' | 'polite' | 'assertive';
}

/**
 * @param props Explicit content, tone and announcement policy.
 * @returns A notice that never retries, dismisses or mutates domain state on its own.
 */
export default function Alert({ title, children, tone = 'info', announce = 'off' }: AlertProps) {
    let role: 'status' | 'alert' | undefined;

    if (announce === 'polite') role = 'status';
    if (announce === 'assertive') role = 'alert';

    return (
        <div
            className="ui-alert"
            data-tone={tone}
            role={role}
            aria-atomic={role ? true : undefined}
        >
            <p className="ui-alert-title">{title}</p>
            <div className="ui-alert-content">{children}</div>
        </div>
    );
}
