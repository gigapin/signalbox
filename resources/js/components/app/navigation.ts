/** Build the currently implemented team destinations independently of saved account preferences. */

/** Implemented sections; billing and API are intentionally absent until their pages exist. */
export type TeamSection =
    | 'dashboard'
    | 'monitors'
    | 'incidents'
    | 'maintenance'
    | 'notifications'
    | 'status-pages'
    | 'team';

/** Notification workflows that have independent authorized pages. */
export type NotificationSection = 'channels' | 'policies' | 'deliveries' | 'assignments';

/** One child destination of the Notifications navigation group. */
interface NotificationDestination {
    /** Stable workflow identity used for the current-page indicator. */
    section: NotificationSection;
    /** Existing workflow label. */
    label: string;
    /** Authorized team-relative read destination. */
    href: string;
}

/**
 * @param teamId Authorized route team.
 * @returns The four independent notification workflow destinations.
 */
export function notificationDestinations(teamId: number): NotificationDestination[] {
    const base = `/teams/${teamId}/notifications`;
    return [
        { section: 'channels', label: 'Channels', href: `${base}/channels` },
        { section: 'policies', label: 'Escalation policies', href: `${base}/policies` },
        { section: 'deliveries', label: 'Notification deliveries', href: `${base}/deliveries` },
        {
            section: 'assignments',
            label: 'Assign a policy to a monitor',
            href: `${base}/assignments`,
        },
    ];
}

/** One authorized team identity already projected by the route controller. */
export interface AppTeam {
    /** Explicit route team identifier; never inferred from the saved account preference. */
    id: number;
    /** Display name of the authorized team. */
    name: string;
}

/** A concrete destination in the team navigation. */
interface TeamDestination {
    /** Stable section identity used to mark the current page family. */
    section: TeamSection;
    /** Visible and accessible navigation label. */
    label: string;
    /** Relative destination built from the route team only. */
    href: string;
}

/** Optional ancestor in a breadcrumb; the layout appends the current page as plain text. */
export interface BreadcrumbAncestor {
    /** Safe visible ancestor title. */
    label: string;
    /** Application-built relative URL, never a raw returnTo or submitted redirect. */
    href: string;
}

/**
 * @param teamId Controller-authorized route team identifier.
 * @returns All currently implemented read destinations, equally available to team viewers.
 */
export function teamDestinations(teamId: number): TeamDestination[] {
    const base = `/teams/${teamId}`;

    return [
        { section: 'dashboard', label: 'Dashboard', href: `${base}/dashboard` },
        { section: 'monitors', label: 'Monitors', href: `${base}/monitors` },
        { section: 'incidents', label: 'Incidents', href: `${base}/incidents` },
        { section: 'maintenance', label: 'Maintenance', href: `${base}/maintenance` },
        { section: 'notifications', label: 'Notifications', href: `${base}/notifications` },
        { section: 'status-pages', label: 'Status pages', href: `${base}/status-pages` },
        { section: 'team', label: 'Team', href: base },
    ];
}

/** Current membership role projected by the server, not inferred from another team's privileges. */
export type TeamRole = 'owner' | 'admin' | 'member' | 'viewer';

/** Safe selector response, absent on public and platform routes. */
export interface TeamNavigationView {
    /** Only currently accessible teams with their own persisted role. */
    teams: (AppTeam & { role: TeamRole })[];
    /** Valid preference, or null without automatically selecting a membership. */
    selectedTeamId: number | null;
}
