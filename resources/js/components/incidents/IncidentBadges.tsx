/** Render incident status and severity as text badges, never as color alone. */
import { incidentStatusLabel, severityLabel } from './labels';

/** Badge inputs for an incident status. */
interface IncidentStatusBadgeProps {
    /** Incident status value. */
    status: string;
}

/** Badge inputs for a severity. */
interface SeverityBadgeProps {
    /** Severity value. */
    severity: string;
}

/** Color classes per incident status; the text label carries the meaning. */
const STATUS_CLASSES: Record<string, string> = {
    investigating: 'border-red-700 text-red-200',
    identified: 'border-amber-700 text-amber-200',
    monitoring: 'border-sky-700 text-sky-200',
    resolved: 'border-emerald-700 text-emerald-200',
};

/** Color classes per severity. */
const SEVERITY_CLASSES: Record<string, string> = {
    critical: 'border-red-700 text-red-200',
    major: 'border-amber-700 text-amber-200',
    minor: 'border-slate-600 text-slate-300',
};

/** Base badge styling shared by both badges. */
const BASE = 'inline-block rounded border px-2 py-0.5 text-xs font-medium';

/** Show the incident lifecycle status as labelled text. */
export function IncidentStatusBadge({ status }: IncidentStatusBadgeProps) {
    return (
        <span className={`${BASE} ${STATUS_CLASSES[status] ?? STATUS_CLASSES.investigating}`}>
            {incidentStatusLabel(status)}
        </span>
    );
}

/** Show the incident impact as labelled text. */
export function SeverityBadge({ severity }: SeverityBadgeProps) {
    return (
        <span className={`${BASE} ${SEVERITY_CLASSES[severity] ?? SEVERITY_CLASSES.minor}`}>
            {severityLabel(severity)}
        </span>
    );
}
