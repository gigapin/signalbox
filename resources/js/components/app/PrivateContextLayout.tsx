/** Render account and platform workspaces without inferring membership from an inspected team. */
import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useId, useState, type ReactNode } from 'react';
import type { AuthProps } from '../auth/AuthLayout';
import Alert from '../ui/Alert';
import { PageHeading } from '../ui/Panel';
import AccountMenu from './AccountMenu';
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
    const { appName, auth, status } = usePage<AuthProps>().props;
    const [denied, setDenied] = useState(false);
    const deny = useCallback(() => setDenied(true), []);
    const hidden = denied || auth?.user === null;
    const mainId = useId();
    const platform = context === 'platform';
    return (
        <TeamAccessContext.Provider value={deny}>
            <div className="ui-theme ui-app-shell">
                <Head title={hidden ? 'Account access unavailable' : title} />
                <a className="ui-skip-link ui-link" href={`#${mainId}`}>
                    Skip to content
                </a>
                <header className="ui-app-header">
                    <Link className="ui-brand ui-link" href="/account">
                        {appName}
                    </Link>
                    <p className="ui-team-identity">
                        {platform ? 'Platform administration' : 'Account'}
                    </p>
                    {!hidden && auth?.user && (
                        <AccountMenu
                            name={auth.user.name}
                            isPlatformAdmin={auth.user.isPlatformAdmin === true}
                        />
                    )}
                </header>
                <main id={mainId} tabIndex={-1} className="ui-app-main">
                    <nav aria-label="Workspace navigation" className="ui-actions">
                        <Link className="ui-link" href="/account">
                            Your account
                        </Link>
                        {platform ? (
                            <Link className="ui-link" href="/admin/teams">
                                All teams
                            </Link>
                        ) : (
                            <>
                                <Link className="ui-link" href="/teams">
                                    Your teams
                                </Link>
                                <Link className="ui-link" href="/account/security">
                                    Account security
                                </Link>
                            </>
                        )}
                    </nav>
                    <PageHeading
                        title={hidden ? 'Account access unavailable' : title}
                        {...(hidden || description === undefined ? {} : { description })}
                    />
                    {!hidden && status && (
                        <Alert title="Update" tone="success" announce="polite">
                            {status}
                        </Alert>
                    )}
                    <div className="ui-page-content">
                        {hidden ? (
                            <Alert title="Private data hidden" tone="warning" announce="assertive">
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
