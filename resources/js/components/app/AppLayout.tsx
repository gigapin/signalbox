/** Render the private team shell with explicit route identity, section navigation and account controls. */
import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useId, useState, type ReactNode } from 'react';
import type { AuthProps } from '../auth/AuthLayout';
import LiveUpdates from '../LiveUpdates';
import Alert from '../ui/Alert';
import { PageHeading } from '../ui/Panel';
import AccountMenu from './AccountMenu';
import { TeamAccessContext } from './TeamAccessContext';
import TeamSwitcher from './TeamSwitcher';
import TeamLinks from './TeamLinks';
import MobileNavigation from './MobileNavigation';
import { useDesktopNavigation } from './useDesktopNavigation';
import { type AppTeam, type BreadcrumbAncestor, type TeamSection } from './navigation';

/** Inputs deliberately supplied by an authorized team page instead of global preference inference. */
interface AppLayoutProps {
    /** Current page's document title and sole visible h1. */
    title: string;
    /** Authorized route identity used for every resource destination. */
    team: AppTeam;
    /** Current page family, including detail and edit pages. */
    section: TeamSection;
    /** Additional context such as a redacted destination. */
    description?: string;
    /** Application-built ancestors between the team and current page; default empty. */
    ancestors?: BreadcrumbAncestor[];
    /** Caller-authorized page actions. */
    actions?: ReactNode;
    /** Authoritative denial from page-owned recovery removes all old team content. */
    accessDenied?: boolean;
    /** Existing page content and page-owned realtime state. */
    children: ReactNode;
}

/**
 * @param props Page identity, current section, trusted breadcrumbs and existing content.
 * @returns A team shell distinct from authentication, public and platform contexts.
 */
export default function AppLayout({
    title,
    team,
    section,
    description,
    ancestors = [],
    accessDenied = false,
    actions,
    children,
}: AppLayoutProps) {
    const { appName, auth, status, teamNavigation } = usePage<AuthProps>().props;
    const mainId = useId();
    const desktop = useDesktopNavigation();
    const [suspended, setSuspended] = useState(false);
    const [revoked, setRevoked] = useState(false);
    const deny = useCallback(() => setRevoked(true), []);
    const membership = teamNavigation?.teams.find((choice) => choice.id === team.id);
    const inaccessible = teamNavigation != null && membership === undefined;
    const hidden = suspended || inaccessible || accessDenied || revoked;
    const user = auth?.user;

    return (
        <TeamAccessContext.Provider value={deny}>
            <div className="ui-theme ui-app-shell">
                <Head title={title} />
                <a className="ui-skip-link ui-link" href={`#${mainId}`}>
                    Skip to content
                </a>
                <header className="ui-app-header">
                    <Link
                        className="ui-brand ui-link"
                        href={hidden ? '/teams' : `/teams/${team.id}/dashboard`}
                    >
                        {appName}
                    </Link>
                    <div className="ui-team-identity">
                        <span className="ui-help">Current team</span>
                        <p className="ui-team-name">
                            {hidden ? 'Team content unavailable' : team.name}
                        </p>
                    </div>
                    {!hidden && !desktop && (
                        <MobileNavigation
                            key={`mobile:${team.id}`}
                            teamId={team.id}
                            section={section}
                            mainId={mainId}
                        />
                    )}
                    {membership && !hidden && <p className="ui-help">Role: {membership.role}</p>}
                    {teamNavigation && !accessDenied && !revoked && (
                        <TeamSwitcher
                            key={`${team.id}:${user?.email ?? ''}`}
                            navigation={teamNavigation}
                            activeTeamId={team.id}
                            onSuspend={() => setSuspended(true)}
                        />
                    )}
                    {user && !hidden && (
                        <AccountMenu
                            key={`account:${team.id}`}
                            name={user.name}
                            isPlatformAdmin={user.isPlatformAdmin === true}
                        />
                    )}
                </header>
                <div className="ui-app-frame">
                    {!hidden && desktop && (
                        <aside className="ui-sidebar">
                            <TeamLinks teamId={team.id} section={section} />
                        </aside>
                    )}
                    <main id={mainId} tabIndex={-1} data-suspended={hidden} className="ui-app-main">
                        {hidden ? (
                            <>
                                <h1 className="ui-page-title">Team selection</h1>
                                {(accessDenied || revoked) && (
                                    <LiveUpdates phase="denied" retry={() => undefined} />
                                )}
                                <Alert title="Team data hidden" tone="warning">
                                    <p>Check your current teams to continue.</p>
                                    <Link className="ui-link" href="/teams">
                                        Your teams
                                    </Link>
                                </Alert>
                            </>
                        ) : (
                            <>
                                <nav aria-label="Breadcrumb" className="ui-breadcrumb">
                                    <ol>
                                        <li>
                                            <Link className="ui-link" href={`/teams/${team.id}`}>
                                                {team.name}
                                            </Link>
                                        </li>
                                        {ancestors.map((ancestor) => (
                                            <li key={ancestor.href}>
                                                <Link className="ui-link" href={ancestor.href}>
                                                    {ancestor.label}
                                                </Link>
                                            </li>
                                        ))}
                                        <li aria-current="page">{title}</li>
                                    </ol>
                                </nav>
                                <PageHeading
                                    title={title}
                                    {...(description === undefined ? {} : { description })}
                                    actions={actions}
                                />
                                {status && (
                                    <Alert title="Update" tone="success" announce="polite">
                                        {status}
                                    </Alert>
                                )}
                                <div className="ui-page-content">{children}</div>
                            </>
                        )}
                    </main>
                </div>
            </div>
        </TeamAccessContext.Provider>
    );
}
