/** Describe the incident and maintenance projections sent by IncidentPresenter and MaintenanceWindowPresenter. */
import type { MonitorPageProps } from '../monitors/types';

/** Minimal identity of the failing monitor. */
export interface IncidentMonitor {
    /** Team-local monitor identifier. */
    id: number;
    /** Human-readable monitor label. */
    name: string;
}

/** One incident row, shared by lists and the detail page. */
export interface IncidentSummary {
    /** Team-local incident identifier. */
    id: number;
    /** Failing monitor. */
    monitor: IncidentMonitor;
    /** `investigating`, `identified`, `monitoring` or `resolved`. */
    status: string;
    /** `critical`, `major` or `minor`. */
    severity: string;
    /** Stable check error value of the confirming failure, such as `timeout`. */
    cause: string;
    /** ISO-8601 UTC slot of the first failure in the confirmed sequence. */
    startedAt: string;
    /** ISO-8601 UTC instant the confirmation opened the incident. */
    confirmedAt: string;
    /** ISO-8601 UTC latest resolution, or null while open. */
    resolvedAt: string | null;
    /** Seconds from start to resolution, or null while open. */
    durationSeconds: number | null;
    /** Whether someone has taken responsibility. */
    acknowledged: boolean;
    /** Number of automatic reopenings. */
    reopenedCount: number;
}

/** One append-only timeline entry. */
export interface TimelineEntry {
    /** Entry identifier, used by the publish endpoint. */
    id: number;
    /** Incident status after this entry. */
    status: string;
    /** Entry text. */
    message: string;
    /** Author's display name; null for system entries and deleted accounts. */
    author: string | null;
    /** Whether the entry was explicitly published. */
    isPublic: boolean;
    /** ISO-8601 UTC publication instant, or null while private. */
    publishedAt: string | null;
    /** ISO-8601 UTC creation instant. */
    createdAt: string | null;
}

/** Detail projection of one incident. */
export interface IncidentDetail extends IncidentSummary {
    /** ISO-8601 UTC instant responsibility was taken, or null. */
    acknowledgedAt: string | null;
    /** Responsible user's display name, or null when nobody is responsible or the account was deleted. */
    responsible: string | null;
    /** Cause declared by people, or null. */
    rootCauseNote: string | null;
    /** ISO-8601 UTC latest reopening, or null. */
    lastReopenedAt: string | null;
    /** Entries oldest first. */
    timeline: TimelineEntry[];
}

/** One page of incident rows with counters; links are built client-side from the active filters. */
export interface IncidentPage {
    /** Rows of the current page. */
    data: IncidentSummary[];
    /** One-based current page. */
    currentPage: number;
    /** Last available page, at least one. */
    lastPage: number;
    /** Rows per page. */
    perPage: number;
    /** Matching rows across all pages. */
    total: number;
    /** One-based index of the first row shown, or null on an empty page. */
    from: number | null;
    /** One-based index of the last row shown, or null on an empty page. */
    to: number | null;
}

/** Accepted list filters echoed by the server; null means "any". */
export interface IncidentFilters {
    /** `open` or `resolved`. */
    state: string | null;
    /** Exact severity. */
    severity: string | null;
}

/** Enum values offered by filters and forms, supplied by the server. */
export interface IncidentOptions {
    /** List states. */
    states: string[];
    /** Severities, least severe first. */
    severities: string[];
}

/** Props shared by every incident page. */
export type IncidentPageProps = MonitorPageProps;

/** One maintenance window with its phase relative to the server's clock. */
export interface MaintenanceWindowView {
    /** Window identifier. */
    id: number;
    /** ISO-8601 UTC inclusive start. */
    startsAt: string;
    /** ISO-8601 UTC exclusive end. */
    endsAt: string;
    /** Explanation entered when scheduling. */
    reason: string;
    /** `upcoming` (editable, deletable), `ongoing` (can end now) or `finished` (history). */
    phase: string;
}

/** Maintenance section of the monitor detail page. */
export interface MaintenanceView {
    /** Ongoing and upcoming windows, earliest start first. */
    current: MaintenanceWindowView[];
    /** Latest finished windows, latest end first. */
    finished: MaintenanceWindowView[];
    /** Longest accepted reason, in characters. */
    reasonMaxLength: number;
}
