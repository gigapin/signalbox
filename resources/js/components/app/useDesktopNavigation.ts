/** Observe the desktop navigation breakpoint without owning screen size in page state. */
import { useSyncExternalStore } from 'react';

/** Match the single breakpoint used by the private shell CSS. */
export const DESKTOP_NAVIGATION_QUERY = '(min-width: 1024px)';

/**
 * @param changed Notify React only when the breakpoint changes, including browser zoom/reflow.
 * @returns Listener cleanup; environments without matchMedia retain the desktop baseline.
 */
function subscribe(changed: () => void): () => void {
    if (typeof window.matchMedia !== 'function') return () => undefined;
    const media = window.matchMedia(DESKTOP_NAVIGATION_QUERY);
    media.addEventListener('change', changed);
    return () => media.removeEventListener('change', changed);
}

/** @returns Current desktop breakpoint; DOM test environments without matchMedia use desktop. */
function snapshot(): boolean {
    return (
        typeof window.matchMedia !== 'function' ||
        window.matchMedia(DESKTOP_NAVIGATION_QUERY).matches
    );
}

/** @returns Stable server baseline; client hydration subsequently observes the real viewport. */
function serverSnapshot(): boolean {
    return true;
}

/** @returns Whether the shell must render a desktop sidebar rather than a mobile trigger/dialog. */
export function useDesktopNavigation(): boolean {
    return useSyncExternalStore(subscribe, snapshot, serverSnapshot);
}
