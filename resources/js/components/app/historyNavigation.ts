/** Install history interception before Inertia initializes its own window listener. */

/** Current mounted provider's handler; removing it releases every page/form closure. */
let handler: ((event: PopStateEvent) => void) | null = null;
/** One bootstrap-owned dispatcher per browser window, matching the Inertia application's lifetime. */
let installed = false;

/** Forward only to the currently mounted private-navigation coordinator. */
function dispatch(event: PopStateEvent): void {
    handler?.(event);
}

/**
 * Call before mounting Inertia: window-level popstate listeners must be registered before its consumer.
 * The dispatcher persists for the application lifetime and retains no account, field or page data.
 */
export function prepareHistoryNavigation(): void {
    if (installed || typeof window === 'undefined') return;
    window.addEventListener('popstate', dispatch, true);
    installed = true;
}

/**
 * @param next Mounted provider's history handler.
 * @returns Cleanup releasing the handler without changing the bootstrap listener's ordering.
 */
export function registerHistoryNavigation(next: (event: PopStateEvent) => void): () => void {
    handler = next;
    return () => {
        if (handler === next) handler = null;
    };
}
