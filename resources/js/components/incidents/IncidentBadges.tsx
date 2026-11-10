/** Render incident status and severity as text badges, never as color alone. */
import Badge, { type Tone } from '../ui/Badge';
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

/** Semantic tones per incident status; the text label carries the meaning. */
const STATUS_TONES: Record<string, Tone> = {
    investigating: 'danger',
    identified: 'warning',
    monitoring: 'info',
    resolved: 'success',
};

/** Semantic tones per severity. */
const SEVERITY_TONES: Record<string, Tone> = {
    critical: 'danger',
    major: 'warning',
    minor: 'neutral',
};

/** Show the incident lifecycle status as labelled text. */
export function IncidentStatusBadge({ status }: IncidentStatusBadgeProps) {
    return <Badge tone={STATUS_TONES[status] ?? 'neutral'}>{incidentStatusLabel(status)}</Badge>;
}

/** Show the incident impact as labelled text. */
export function SeverityBadge({ severity }: SeverityBadgeProps) {
    return <Badge tone={SEVERITY_TONES[severity] ?? 'neutral'}>{severityLabel(severity)}</Badge>;
}
