/** Coordinate confirmed application appearance across Inertia pages without overriding published themes. */
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { csrfHeader } from '../csrf';

/** Closed application appearance values. */
type Theme = 'dark' | 'light';
/** Shared pending and failure state prevents concurrent writes from different controls. */
interface Appearance {
    /** Current confirmed theme. */
    theme: Theme;
    /** Whether a save is in flight. */
    pending: boolean;
    /** Accessible save failure, empty on success. */
    error: string;
    /** Persist the opposite theme without navigating away from drafts. */
    toggle: () => Promise<void>;
}
/** One coordinator for the entire application tree. */
const Context = createContext<Appearance | null>(null);

/** @param props Page descendants. @returns Shared preference state and authenticated mutation boundary. */
export function ThemeProvider({ children }: { children: ReactNode }) {
    const { uiTheme } = usePage().props;
    const [override, setOverride] = useState<{ baseline: unknown; theme: Theme } | null>(null);
    const theme: Theme =
        override !== null && override.baseline === uiTheme
            ? override.theme
            : uiTheme === 'light'
              ? 'light'
              : 'dark';
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        if (uiTheme === null) delete document.documentElement.dataset.uiTheme;
        else document.documentElement.dataset.uiTheme = theme;
    }, [theme, uiTheme]);

    /** Save only an explicit account choice and keep the previous theme on any failed acknowledgement. */
    async function toggle(): Promise<void> {
        if (pending) return;
        setPending(true);
        setError('');
        const next = theme === 'dark' ? 'light' : 'dark';
        try {
            const response = await fetch('/preferences/theme', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: AbortSignal.timeout(10000),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfHeader('Theme could not be saved.'),
                },
                body: JSON.stringify({ theme: next }),
            });
            const data: unknown = await response.json();
            if (
                !response.ok ||
                response.redirected ||
                typeof data !== 'object' ||
                data === null ||
                !('theme' in data) ||
                data.theme !== next
            )
                throw new Error('Invalid acknowledgement');
            setOverride({ baseline: uiTheme, theme: next });
        } catch {
            setError('Theme could not be saved. Please try again.');
        } finally {
            setPending(false);
        }
    }
    return (
        <Context.Provider value={{ theme, pending, error, toggle }}>{children}</Context.Provider>
    );
}

/** @returns Header-only accessible toggle; welcome and authentication screens never mount it. */
export function ThemeToggle() {
    const appearance = useContext(Context);
    if (!appearance) return null;
    return (
        <div>
            <button
                type="button"
                className="ui-theme-toggle"
                aria-label={appearance.theme === 'dark' ? 'Light mode' : 'Dark mode'}
                title={appearance.theme === 'dark' ? 'Light mode' : 'Dark mode'}
                disabled={appearance.pending}
                onClick={() => {
                    void appearance.toggle();
                }}
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    width="20"
                    height="20"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.75"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    {appearance.theme === 'dark' ? (
                        <>
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" />
                        </>
                    ) : (
                        <path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z" />
                    )}
                </svg>
            </button>
            {appearance.error && (
                <p role="alert" className="ui-help">
                    {appearance.error}
                </p>
            )}
        </div>
    );
}
