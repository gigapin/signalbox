/** Share account and platform destinations throughout the dashboard shell without selecting a tenant. */
import { Link, usePage } from '@inertiajs/react';
import { focusDestination } from './TeamLinks';
import type { AuthProps } from '../auth/AuthLayout';

/** Context-neutral navigation and optional mobile dismissal. */
interface WorkspaceLinksProps {
    /** Team navigation already provides a dashboard destination when false. */
    includeDashboard?: boolean;
    /** Close the mobile navigation for an ordinary same-tab visit. */
    onNavigate?: (() => void) | undefined;
}

/** Render account-level links with an operator-only administration entry. */
export default function WorkspaceLinks({
    includeDashboard = true,
    onNavigate,
}: WorkspaceLinksProps) {
    const { auth } = usePage<AuthProps>().props;
    const path = usePage().url.split('?')[0] ?? '';
    const destinations = [
        ...(includeDashboard ? [{ href: '/dashboard', label: 'Dashboard' }] : []),
        { href: '/teams', label: 'Teams' },
        { href: '/account', label: 'Account' },
        { href: '/account/security', label: 'Security' },
        ...(auth?.user?.isPlatformAdmin === true
            ? [{ href: '/admin/teams', label: 'Administration' }]
            : []),
    ];
    return (
        <nav aria-label="Workspace navigation">
            <ul className="ui-team-links">
                {destinations.map(({ href, label }) => (
                    <li key={href}>
                        <Link
                            className="ui-shell-link"
                            href={href}
                            aria-current={
                                (
                                    href === '/admin/teams'
                                        ? path.startsWith('/admin/teams')
                                        : href === '/teams'
                                          ? /^\/teams(?:$|\/\d+\/(?:members|invitations|ownership)(?:$|[?#]))/.test(
                                                path,
                                            )
                                          : path === href
                                )
                                    ? 'page'
                                    : undefined
                            }
                            onSuccess={focusDestination}
                            onClick={(event) => {
                                if (
                                    event.button === 0 &&
                                    !event.metaKey &&
                                    !event.ctrlKey &&
                                    !event.shiftKey &&
                                    !event.altKey
                                )
                                    onNavigate?.();
                            }}
                        >
                            {label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
