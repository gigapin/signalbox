/** Render the private team shell with explicit route identity, section navigation and account controls. */
import { Head, Link, usePage } from '@inertiajs/react';
import { useId, type ReactNode } from 'react';
import type { AuthProps } from '../auth/AuthLayout';
import Alert from '../ui/Alert';
import { PageHeading } from '../ui/Panel';
import AccountMenu from './AccountMenu';
import {
    teamDestinations,
    type AppTeam,
    type BreadcrumbAncestor,
    type TeamSection,
} from './navigation';

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
    actions,
    children,
}: AppLayoutProps) {
    const { appName, auth, status } = usePage<AuthProps>().props;
    const mainId = useId();
    const user = auth?.user;

    return (
        <div className="ui-theme ui-app-shell">
            <Head title={title} />
            <a className="ui-skip-link ui-link" href={`#${mainId}`}>
                Skip to content
            </a>
            <header className="ui-app-header">
                <Link className="ui-brand ui-link" href={`/teams/${team.id}/dashboard`}>
                    {appName}
                </Link>
                <div className="ui-team-identity">
                    <span className="ui-help">Current team</span>
                    <p className="ui-team-name">{team.name}</p>
                </div>
                {user && (
                    <AccountMenu
                        key={team.id}
                        name={user.name}
                        isPlatformAdmin={user.isPlatformAdmin === true}
                    />
                )}
            </header>
            <div className="ui-app-frame">
                <aside className="ui-sidebar">
                    <nav aria-label="Team navigation">
                        <ul className="ui-team-links">
                            {teamDestinations(team.id).map((destination) => (
                                <li key={destination.section}>
                                    <Link
                                        className="ui-shell-link"
                                        href={destination.href}
                                        aria-current={
                                            section === destination.section ? 'page' : undefined
                                        }
                                    >
                                        {destination.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </aside>
                <main id={mainId} tabIndex={-1} className="ui-app-main">
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
                </main>
            </div>
        </div>
    );
}
