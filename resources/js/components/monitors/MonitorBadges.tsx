/** Render observed status and operational state as text badges, never as color alone. */
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

/** Color classes per observed status; the text label carries the meaning. */
const STATUS_CLASSES: Record<string, string> = {
    up: 'border-emerald-700 text-emerald-200',
    down: 'border-red-700 text-red-200',
    unknown: 'border-slate-600 text-slate-300',
};

/** Color classes per operational state. */
const STATE_CLASSES: Record<string, string> = {
    active: 'border-slate-600 text-slate-300',
    paused: 'border-amber-700 text-amber-200',
    suspended: 'border-red-700 text-red-200',
};

/** Base badge styling shared by both badges. */
const BASE = 'inline-block rounded border px-2 py-0.5 text-xs font-medium';

/** Show the latest observation as labelled text. */
export function StatusBadge({ status }: StatusBadgeProps) {
    return (
        <span className={`${BASE} ${STATUS_CLASSES[status] ?? STATUS_CLASSES.unknown}`}>
            {statusLabel(status)}
        </span>
    );
}

/** Show whether the monitor is active, paused or suspended by its plan. */
export function StateBadge({ state }: StateBadgeProps) {
    return (
        <span className={`${BASE} ${STATE_CLASSES[state] ?? STATE_CLASSES.active}`}>
            {stateLabel(state)}
        </span>
    );
}
