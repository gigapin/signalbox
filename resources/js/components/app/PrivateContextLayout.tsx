/** Render account and platform pages inside the dashboard frame without inferring an inspected tenant. */
import { ThemeToggle } from '../appearance/Theme';
import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useId, useState, type ReactNode } from 'react';
import type { AuthProps } from '../auth/AuthLayout';
import Alert from '../ui/Alert';
import { PageHeading } from '../ui/Panel';
import AccountMenu from './AccountMenu';
import MobileNavigation from './MobileNavigation';
import WorkspaceLinks from './WorkspaceLinks';
import TeamLinks from './TeamLinks';
import { useDesktopNavigation } from './useDesktopNavigation';
import { TeamAccessContext } from './TeamAccessContext';

/** Explicit non-tenant workspace inputs; platform context never offers a team selector. */
interface ContextLayoutProps {
    /** Current visible/document title. */
    title: string;
    /** Account by default, platform only for server-authorized operator pages. */
    context?: 'account' | 'platform';
    /** Safe explanation of the current screen. */
    description?: string;
    /** Existing private forms and content. */
    children: ReactNode;
}

/**
 * @param props Account or operator workspace and page content.
 * @returns The shared private visual shell with a context-specific navigation.
 */
function ContextShell({ title, context = 'account', description, children }: ContextLayoutProps) {
    const { appName, auth, status, teamNavigation } = usePage<AuthProps>().props;
    const [denied, setDenied] = useState(false);
    const deny = useCallback(() => setDenied(true), []);
    const platform = context === 'platform';
    const hidden =
        denied || auth?.user === null || (platform && auth?.user?.isPlatformAdmin !== true);
    const deniedTitle = platform ? 'Platform access unavailable' : 'Account access unavailable';
    const mainId = useId();
    const desktop = useDesktopNavigation();
    const selectedTeam = platform
        ? undefined
        : teamNavigation?.teams.find((team) => team.id === teamNavigation.selectedTeamId);
    /** Offer only the actor's membership navigation; inspected platform teams never supply it. */
    function navigation(onNavigate?: () => void): ReactNode {
        return (
            <>
                {selectedTeam && <TeamLinks teamId={selectedTeam.id} onNavigate={onNavigate} />}
                <WorkspaceLinks includeDashboard={!selectedTeam} onNavigate={onNavigate} />
            </>
        );
    }
    return (
        <TeamAccessContext.Provider value={deny}>
            <div className="ui-theme ui-app-shell">
                <Head title={hidden ? deniedTitle : title} />
                <a className="ui-skip-link ui-link" href={`#${mainId}`}>
                    Skip to content
                </a>
                <header className="ui-app-header">
                    <Link className="ui-brand ui-link" href="/dashboard">
                        {appName}
                    </Link>
                    <p className="ui-team-identity">
                        {platform ? 'Platform administration' : 'Account'}
                    </p>
                    {!hidden && !desktop && (
                        <MobileNavigation
                            mainId={mainId}
                            teamId={selectedTeam?.id}
                            workspaceNavigation
                        />
                    )}
                    {!hidden && <ThemeToggle />}
                    {!hidden && auth?.user && (
                        <AccountMenu
                            name={auth.user.name}
                            isPlatformAdmin={auth.user.isPlatformAdmin === true}
                        />
                    )}
                </header>
                <div className="ui-app-frame">
                    {!hidden && desktop && (
                        <aside className="ui-sidebar">
                            <div className="ui-sidebar-navigation">{navigation()}</div>
                        </aside>
                    )}
                    <main id={mainId} tabIndex={-1} data-suspended={hidden} className="ui-app-main">
                        <PageHeading
                            title={hidden ? deniedTitle : title}
                            {...(hidden || description === undefined ? {} : { description })}
                        />
                        {!hidden && status && (
                            <Alert title="Update" tone="success" announce="polite">
                                {status}
                            </Alert>
                        )}
                        <div className="ui-page-content">
                            {hidden ? (
                                <Alert
                                    title="Private data hidden"
                                    tone="warning"
                                    announce="assertive"
                                >
                                    <p>Sign in again or reload to verify your current access.</p>
                                    <Link className="ui-link" href="/login">
                                        Sign in
                                    </Link>
                                </Alert>
                            ) : (
                                children
                            )}
                        </div>
                    </main>
                </div>
            </div>
        </TeamAccessContext.Provider>
    );
}

/** Remount private form and credential state when the authenticated identity or workspace changes. */
export default function PrivateContextLayout(props: ContextLayoutProps) {
    const { auth } = usePage<AuthProps>().props;
    return (
        <ContextShell key={`${props.context ?? 'account'}:${auth?.user?.email ?? ''}`} {...props} />
    );
}
