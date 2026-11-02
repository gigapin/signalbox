/** Describe the status page projections sent by StatusPageController and PublicStatusPageController. */
import type { MonitorPageProps } from '../monitors/types';

/** Appearance of a page, with values from closed server enums. */
export interface StatusPageTheme {
    /** Color scheme: `system`, `light` or `dark`. */
    mode: string;
    /** Decorative accent preset. */
    accent: string;
}

/** A monitor a component can group. */
export interface SelectableMonitor {
    /** Team-local monitor identity. */
    id: number;
    /** Internal monitor name, never shown to visitors. */
    name: string;
}

/** One component of a page as managers see it. */
export interface ManagedComponent {
    /** Public label. */
    name: string;
    /** Grouped monitors by name. */
    monitors: SelectableMonitor[];
}

/** Public DNS proof and expiring ownership status of a custom hostname. */
export interface ManagedStatusDomain {
    /** Canonical ASCII customer hostname. */
    hostname: string;
    /** Absolute DNS TXT owner name. */
    proofName: string;
    /** Public random verification proof, retained for periodic revalidation. */
    proofValue: string;
    /** Exclusive UTC lease expiry; null without a current verified binding. */
    verifiedUntil: string | null;
    /** Last attempted ownership lookup in UTC. */
    checkedAt: string | null;
}

/** One page row of the settings screen; credentials themselves are never sent. */
export interface ManagedStatusPage {
    /** Team-local page identity. */
    id: number;
    /** Public address segment. */
    slug: string;
    /** Public path of the page. */
    path: string;
    /** Page heading. */
    title: string;
    /** Optional introduction. */
    description: string | null;
    /** Appearance. */
    theme: StatusPageTheme;
    /** Whether the public address answers; false is a draft. */
    isPublic: boolean;
    /** Whether visitors must enter a password. */
    hasPassword: boolean;
    /** Independent custom hostname claim and live proof status. */
    domain: ManagedStatusDomain | null;
    /** Components in display order. */
    components: ManagedComponent[];
}

/** Form bounds and plan usage. */
export interface StatusPageLimits {
    /** Maximum pages of the effective plan. */
    pages: number;
    /** Pages the team holds, drafts included. */
    used: number;
    /** Longest title in characters. */
    titleMaxLength: number;
    /** Longest description in characters. */
    descriptionMaxLength: number;
    /** Longest component name in characters. */
    componentNameMaxLength: number;
    /** Most components on one page. */
    componentsPerPage: number;
    /** Most monitors in one component. */
    monitorsPerComponent: number;
    /** Shortest password in characters. */
    passwordMinLength: number;
}

/** Appearance choices offered by the forms. */
export interface ThemeChoices {
    /** Color schemes. */
    modes: string[];
    /** Accent presets. */
    accents: string[];
}

/** Props of the settings page. */
export interface StatusPagesPageProps extends MonitorPageProps {
    /** Route team. */
    team: { id: number; name: string };
    /** Pages of the team. */
    pages: ManagedStatusPage[];
    /** Monitors of the team. */
    monitors: SelectableMonitor[];
    /** Appearance choices. */
    themeChoices: ThemeChoices;
    /** Form bounds and usage. */
    limits: StatusPageLimits;
    /** Whether management controls are offered; the server decides again. */
    can: { manage: boolean };
    /** Validation messages by field. */
    errors: Record<string, string | undefined>;
}

/** One whole UTC day of a component's public history. */
export interface PublicHistoryDay {
    /** ISO date. */
    date: string;
    /** `up`, `down` with any down time, `none` without observed time. */
    state: string;
    /** Observed uptime, rounded down; null without observed time. */
    uptimePercent: number | null;
    /** Share of the day with a known state; null when none of it was watched. */
    coveragePercent: number | null;
}

/** One component as visitors see it. */
export interface PublicComponent {
    /** Public label. */
    name: string;
    /** Current state. */
    status: string;
    /** Observed uptime over the history; null without observed time. */
    uptimePercent: number | null;
    /** Days of the history, oldest first. */
    days: PublicHistoryDay[];
}

/** One explicitly published entry of an incident. */
export interface PublicIncidentUpdate {
    /** Incident status stated by this entry. */
    status: string;
    /** Published text. */
    message: string;
    /** UTC publication instant. */
    publishedAt: string;
}

/** One incident with at least one published entry. */
export interface PublicIncident {
    /** Name of the affected component. */
    component: string;
    /** Status of the latest published entry. */
    status: string;
    /** Published entries, newest first. */
    updates: PublicIncidentUpdate[];
}

/** What a visitor may read: no identity and no monitor name. */
export interface PublicStatusPage {
    /** Page heading. */
    title: string;
    /** Optional introduction. */
    description: string | null;
    /** Appearance. */
    theme: StatusPageTheme;
    /** Overall current state. */
    status: string;
    /** Components in display order. */
    components: PublicComponent[];
    /** Published incidents, newest first. */
    incidents: PublicIncident[];
}
