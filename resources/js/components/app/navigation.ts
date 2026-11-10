/** Build the currently implemented team destinations independently of saved account preferences. */

/** Implemented sections; maintenance, billing and API are intentionally absent until their pages exist. */
export type TeamSection =
    'dashboard' | 'monitors' | 'incidents' | 'notifications' | 'status-pages' | 'team';

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
