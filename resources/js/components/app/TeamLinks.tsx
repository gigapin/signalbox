/** Share one set of concrete team links between desktop and modal mobile navigation. */
import { Link } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { teamDestinations, type TeamSection } from './navigation';

/** One authorized team, its current section and an optional mobile close action. */
interface TeamLinksProps {
    /** Controller-authorized route team identifier. */
    teamId: number;
    /** Current page family; detail pages retain their parent's active section. */
    section: TeamSection;
    /** Close the mobile dialog for a same-tab visit; modified clicks retain normal browser behavior. */
    onNavigate?: () => void;
}

/** Focus the destination heading after Inertia has committed its new page, without stealing later visits' focus. */
function focusDestination(): void {
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
export default function TeamLinks({ teamId, section, onNavigate }: TeamLinksProps) {
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
                        <Link
                            className="ui-shell-link"
                            href={destination.href}
                            aria-current={section === destination.section ? 'page' : undefined}
                            onClick={navigate}
                            onSuccess={focusDestination}
                        >
                            {destination.label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
