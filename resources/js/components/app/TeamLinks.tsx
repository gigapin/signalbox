/** Share one set of concrete team links between desktop and modal mobile navigation. */
import { Link } from '@inertiajs/react';
import { useId, useState, type MouseEvent } from 'react';
import Button from '../ui/Button';
import {
    notificationDestinations,
    teamDestinations,
    type NotificationSection,
    type TeamSection,
} from './navigation';

/** One authorized team, its current section and an optional mobile close action. */
interface TeamLinksProps {
    /** Controller-authorized route team identifier. */
    teamId: number;
    /** Current page family; detail pages retain their parent's active section. */
    section?: TeamSection | undefined;
    /** Explicit active notification child; the parent remains a navigation group. */
    notificationSection?: NotificationSection | undefined;
    /** Close the mobile dialog for a same-tab visit; modified clicks retain normal browser behavior. */
    onNavigate?: (() => void) | undefined;
}

/** Focus the destination heading after Inertia has committed its new page, without stealing later visits' focus. */
export function focusDestination(): void {
    const destination = window.location.href;
    window.requestAnimationFrame(() => {
        if (window.location.href !== destination) return;
        const heading = document.querySelector<HTMLElement>('main h1');
        if (!heading) return;
        heading.setAttribute('tabindex', '-1');
        heading.focus();
    });
}

/**
 * @param props Authorized team, section and optional dismissal callback.
 * @returns One named native navigation with exactly one current page family.
 */
export default function TeamLinks({
    teamId,
    section,
    notificationSection,
    onNavigate,
}: TeamLinksProps) {
    const submenuId = useId();
    const [expanded, setExpanded] = useState(section === 'notifications');
    const [previousSection, setPreviousSection] = useState(section);
    if (previousSection !== section) {
        setPreviousSection(section);
        if (section === 'notifications') setExpanded(true);
    }
    /** Dismiss for ordinary same-tab visits only; opening another tab must not collapse the current dialog. */
    function navigate(event: MouseEvent): void {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
            return;
        onNavigate?.();
    }

    return (
        <nav aria-label="Team navigation">
            <ul className="ui-team-links">
                {teamDestinations(teamId).map((destination) => (
                    <li key={destination.section}>
                        <div
                            className={
                                destination.section === 'notifications'
                                    ? 'ui-nav-parent'
                                    : undefined
                            }
                        >
                            <Link
                                className="ui-shell-link"
                                href={destination.href}
                                aria-current={
                                    section === destination.section &&
                                    (destination.section !== 'notifications' ||
                                        notificationSection === undefined)
                                        ? 'page'
                                        : undefined
                                }
                                data-active={section === destination.section || undefined}
                                onClick={navigate}
                                onSuccess={focusDestination}
                            >
                                {destination.label}
                            </Link>
                            {destination.section === 'notifications' && (
                                <Button
                                    variant="secondary"
                                    className="ui-navigation-toggle"
                                    aria-label="Toggle Notifications submenu"
                                    aria-expanded={expanded}
                                    aria-controls={submenuId}
                                    onClick={() => setExpanded(!expanded)}
                                >
                                    <svg
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        aria-hidden="true"
                                    >
                                        <path d={expanded ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6'} />
                                    </svg>
                                </Button>
                            )}
                        </div>
                        {destination.section === 'notifications' && (
                            <ul
                                id={submenuId}
                                className="ui-notification-submenu"
                                hidden={!expanded}
                            >
                                {notificationDestinations(teamId).map((child) => (
                                    <li key={child.section}>
                                        <Link
                                            className="ui-shell-link"
                                            href={child.href}
                                            aria-current={
                                                section === 'notifications' &&
                                                notificationSection === child.section
                                                    ? 'page'
                                                    : undefined
                                            }
                                            onClick={navigate}
                                            onSuccess={focusDestination}
                                        >
                                            {child.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>
        </nav>
    );
}
