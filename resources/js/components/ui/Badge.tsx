/** Present a short textual status with a semantic tone, without live-region side effects. */
import type { ReactNode } from 'react';

/** Presentational tone only; domain labels and state transitions belong to the caller. */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

/** Human-readable status content; never convey meaning only with a dot or color. */
interface BadgeProps {
    /** Short explicit status label, including unknown and unavailable states. */
    children: ReactNode;
    /** Semantic color family, defaulting to neutral. */
    tone?: Tone;
}

/**
 * @param props Text status and optional semantic tone.
 * @returns A static inline status label; no inferred business meaning or announcements.
 */
export default function Badge({ children, tone = 'neutral' }: BadgeProps) {
    return (
        <span className="ui-badge" data-tone={tone}>
            {children}
        </span>
    );
}
