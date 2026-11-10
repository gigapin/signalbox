/** Render observed status and operational state as text badges, never as color alone. */
import Badge, { type Tone } from '../ui/Badge';
import { stateLabel, statusLabel } from './labels';

/** Badge inputs for one monitor. */
interface StatusBadgeProps {
    /** Observed status value. */
    status: string;
}

/** Badge inputs for one monitor's scheduling eligibility. */
interface StateBadgeProps {
    /** Operational state value. */
    state: string;
}

/** Semantic tones per observed status; the text label carries the meaning. */
const STATUS_TONES: Record<string, Tone> = {
    up: 'success',
    down: 'danger',
    unknown: 'neutral',
};

/** Semantic tones per operational state. */
const STATE_TONES: Record<string, Tone> = {
    active: 'neutral',
    paused: 'warning',
    suspended: 'danger',
};

/** Show the latest observation as labelled text. */
export function StatusBadge({ status }: StatusBadgeProps) {
    return <Badge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(status)}</Badge>;
}

/** Show whether the monitor is active, paused or suspended by its plan. */
export function StateBadge({ state }: StateBadgeProps) {
    return <Badge tone={STATE_TONES[state] ?? 'neutral'}>{stateLabel(state)}</Badge>;
}
