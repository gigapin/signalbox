/** Coordinate private draft departure without persisting form data or replaying domain mutations. */
import { router } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import Button from '../ui/Button';
import { registerHistoryNavigation } from './historyNavigation';

/** Only navigation-relevant flags, never field values or credentials. */
interface Protection {
    /** An unsaved draft is present. */
    dirty: boolean;
    /** A request or credential confirmation is in progress. */
    busy: boolean;
}

/** Registry and departure gate shared by private forms and team selection. */
interface Guard {
    /** Aggregate request state, used to explain unavailable team switching. */
    busy: boolean;
    /** Bypass only for an authoritative access redirect or an acknowledged navigation, never a mutation. */
    runAuthorizedNavigation: (navigate: () => void) => void;
    /** Register a mounted form and remove it when its flags change or it unmounts. */
    register: (id: string, protection: Protection) => () => void;
    /** Resolve only an explicit departure decision; pending requests always refuse departure. */
    confirmLeave: () => Promise<boolean>;
}

/** Default allows isolated primitives and public forms to render without a private-page provider. */
const GuardContext = createContext<Guard>({
    busy: false,
    runAuthorizedNavigation: (navigate) => navigate(),
    register: () => () => undefined,
    confirmLeave: () => Promise.resolve(true),
});

/** Private browser-history metadata contains an entry position only, with no account or draft data. */
const POSITION = '__signalboxNavigationPosition';

/**
 * @param state Unknown browser history entry.
 * @returns A shallow record that preserves Inertia's page/encryption/scroll state.
 */
function record(state: unknown): Record<string, unknown> {
    return typeof state === 'object' && state !== null ? { ...state } : {};
}

/** One explicit pending departure, retaining only its completion callback and focus origin. */
interface Decision {
    /** Resolve the waiting navigation without retaining its form values. */
    resolve: (allowed: boolean) => void;
    /** Restore keyboard focus when the user stays. */
    origin: HTMLElement | null;
}

/** Application-lifetime coordination mounted outside page replacement. */
interface NavigationGuardProviderProps {
    /** Inertia application and global connection feedback. */
    children: ReactNode;
}

/**
 * @param props Application tree; mount once outside the Inertia page lifecycle.
 * @returns A flag registry and an accessible single departure prompt.
 */
export function NavigationGuardProvider({ children }: NavigationGuardProviderProps) {
    const [forms, setForms] = useState(new Map<string, Protection>());
    const [requests, setRequests] = useState(0);
    const [decision, setDecision] = useState<Decision | null>(null);
    const [notice, setNotice] = useState('');
    const dialog = useRef<HTMLDialogElement>(null);
    const stay = useRef<HTMLButtonElement>(null);
    const resolver = useRef<Decision | null>(null);
    const bypass = useRef(false);
    const headingId = useId();
    const runAuthorizedNavigation = useCallback((navigate: () => void): void => {
        bypass.current = true;
        try {
            navigate();
        } finally {
            bypass.current = false;
        }
    }, []);
    const dirty = [...forms.values()].some((form) => form.dirty);
    const busy = requests > 0 || [...forms.values()].some((form) => form.busy);

    const register = useCallback((id: string, protection: Protection) => {
        setForms((current) => new Map(current).set(id, protection));
        return () =>
            setForms((current) => {
                const next = new Map(current);
                next.delete(id);
                return next;
            });
    }, []);

    const confirmLeave = useCallback((): Promise<boolean> => {
        if (busy) {
            setNotice('Finish the current request before leaving this page.');
            return Promise.resolve(false);
        }
        if (!dirty) return Promise.resolve(true);
        if (resolver.current) return Promise.resolve(false);
        return new Promise<boolean>((resolve) => {
            const pending = {
                resolve,
                origin:
                    document.activeElement instanceof HTMLElement ? document.activeElement : null,
            };
            resolver.current = pending;
            setDecision(pending);
        });
    }, [busy, dirty]);

    /** Complete a user decision once; a newly started request cannot be overridden by Discard. */
    function decide(allowed: boolean): void {
        if (allowed && busy) return;
        const pending = resolver.current;
        resolver.current = null;
        setDecision(null);
        if (!allowed) pending?.origin?.focus();
        pending?.resolve(allowed);
    }

    useEffect(() => {
        if (dirty || busy || !resolver.current) return;
        const pending = resolver.current;
        resolver.current = null;
        dialog.current?.close();
        setDecision(null);
        pending.resolve(false);
        if (pending.origin?.isConnected) pending.origin.focus();
        else document.querySelector<HTMLElement>('main')?.focus();
    }, [dirty, busy]);

    useEffect(() => {
        if (!decision) return;
        const element = dialog.current;
        element?.showModal();
        stay.current?.focus();
        return () => {
            if (element?.open) element.close();
        };
    }, [decision]);

    useEffect(
        () => () => {
            resolver.current?.resolve(false);
            resolver.current = null;
        },
        [],
    );

    useEffect(() => {
        const stopStart = router.on('start', (event) => {
            if (event.detail.visit.method !== 'get') setRequests((count) => count + 1);
        });
        const stopFinish = router.on('finish', (event) => {
            if (event.detail.visit.method !== 'get') setRequests((count) => Math.max(0, count - 1));
        });
        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    useEffect(() => {
        /** Warn for a full document unload without copying draft values to history or storage. */
        function unload(event: BeforeUnloadEvent): void {
            if (!dirty && !busy) return;
            event.preventDefault();
            event.returnValue = '';
        }

        /** Preserve the original Link callbacks by resuming its normal click only after confirmation. */
        function click(event: MouseEvent): void {
            if (
                bypass.current ||
                (!dirty && !busy) ||
                event.button !== 0 ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey
            )
                return;
            const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (
                !(anchor instanceof HTMLAnchorElement) ||
                anchor.target === '_blank' ||
                anchor.hasAttribute('download')
            )
                return;
            const destination = new URL(anchor.href);
            if (
                destination.origin !== location.origin ||
                (destination.pathname === location.pathname &&
                    destination.search === location.search &&
                    destination.hash)
            )
                return;
            event.preventDefault();
            event.stopImmediatePropagation();
            void confirmLeave().then((allowed) => {
                if (!allowed || !anchor.isConnected) return;
                bypass.current = true;
                try {
                    anchor.click();
                } finally {
                    bypass.current = false;
                }
            });
        }

        const stopBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                bypass.current ||
                (!dirty && !busy) ||
                (visit.method !== 'get' && visit.url.pathname !== '/logout')
            )
                return;
            event.preventDefault();
            void confirmLeave().then((allowed) => {
                if (!allowed) return;
                bypass.current = true;
                try {
                    router.visit(visit.url, visit);
                } finally {
                    bypass.current = false;
                }
            });
        });
        document.addEventListener('click', click, true);
        window.addEventListener('beforeunload', unload);
        return () => {
            document.removeEventListener('click', click, true);
            window.removeEventListener('beforeunload', unload);
            stopBefore();
        };
    }, [dirty, busy, confirmLeave]);

    useEffect(() => {
        const pushDescriptor = Object.getOwnPropertyDescriptor(history, 'pushState');
        const replaceDescriptor = Object.getOwnPropertyDescriptor(history, 'replaceState');
        const push = history.pushState.bind(history);
        const replace = history.replaceState.bind(history);
        const initial = record(history.state);
        let position = typeof initial[POSITION] === 'number' ? initial[POSITION] : 0;
        let restoring = false;
        let allowedPop = false;
        let delta = 0;
        replace.call(history, { ...initial, [POSITION]: position }, '', location.href);

        /** Keep an entry position alongside Inertia's opaque state, preserving native history length. */
        history.pushState = function (
            data: unknown,
            unused: string,
            url?: string | URL | null,
        ): void {
            position += 1;
            push.call(history, { ...record(data), [POSITION]: position }, unused, url);
        };
        /** Preserve that position when Inertia updates scroll, encryption or remembered state. */
        history.replaceState = function (
            data: unknown,
            unused: string,
            url?: string | URL | null,
        ): void {
            replace.call(history, { ...record(data), [POSITION]: position }, unused, url);
        };

        /** Restore the source entry before asking, so Stay never destroys its page or draft. */
        function pop(event: PopStateEvent): void {
            const target = record(event.state)[POSITION];
            if (typeof target !== 'number') return;
            if (restoring) {
                event.stopImmediatePropagation();
                restoring = false;
                void confirmLeave().then((allowed) => {
                    if (allowed) {
                        allowedPop = true;
                        history.go(delta);
                    }
                });
                return;
            }
            if (allowedPop || (!dirty && !busy)) {
                allowedPop = false;
                position = target;
                return;
            }
            delta = target - position;
            if (delta === 0) return;
            event.stopImmediatePropagation();
            restoring = true;
            history.go(-delta);
        }
        const stopHistory = registerHistoryNavigation(pop);
        return () => {
            stopHistory();
            if (pushDescriptor) Object.defineProperty(history, 'pushState', pushDescriptor);
            else Reflect.deleteProperty(history, 'pushState');
            if (replaceDescriptor)
                Object.defineProperty(history, 'replaceState', replaceDescriptor);
            else Reflect.deleteProperty(history, 'replaceState');
        };
    }, [dirty, busy, confirmLeave]);

    return (
        <GuardContext.Provider value={{ busy, register, confirmLeave, runAuthorizedNavigation }}>
            {children}
            {notice && (
                <div className="ui-theme ui-guard-notice" role="status">
                    {notice}
                    <Button variant="secondary" onClick={() => setNotice('')}>
                        Dismiss
                    </Button>
                </div>
            )}
            <dialog
                ref={dialog}
                className="ui-theme ui-departure-dialog"
                aria-labelledby={headingId}
                onCancel={(event) => {
                    event.preventDefault();
                    decide(false);
                }}
            >
                <h2 id={headingId} className="ui-section-title">
                    Discard unsaved changes?
                </h2>
                <p>Your draft will be lost if you leave this page.</p>
                <div className="ui-actions">
                    <Button ref={stay} variant="secondary" onClick={() => decide(false)}>
                        Stay
                    </Button>
                    <Button variant="danger" disabled={busy} onClick={() => decide(true)}>
                        Discard and leave
                    </Button>
                </div>
            </dialog>
        </GuardContext.Provider>
    );
}

/** @returns The current private departure gate; it never contains form values. */
export function useNavigationGuard(): Guard {
    return useContext(GuardContext);
}

/**
 * @param dirty Whether this mounted private control has an unsaved edit.
 * @param busy Whether it owns pending work that must finish before departure.
 */
export function useLeaveProtection(dirty: boolean, busy: boolean): void {
    const { register } = useNavigationGuard();
    const id = useId();
    useEffect(() => register(id, { dirty, busy }), [register, id, dirty, busy]);
}

/** Minimal structural form contract, independent of payload and transport implementations. */
interface ProtectedForm {
    /** Update the form-owned baseline after acceptance without retaining a second copy of fields. */
    setDefaults: () => void;
    /** Native Inertia flags. */
    isDirty: boolean;
    /** Native request state. */
    processing: boolean;
    /** Whether the last submitted draft was accepted. */
    wasSuccessful: boolean;
}

/**
 * @param form A private Inertia form; values remain exclusively in component memory.
 */
export function useFormProtection(form: ProtectedForm): void {
    const { wasSuccessful: accepted, setDefaults, isDirty, processing } = form;
    const wasSuccessful = useRef(false);
    useEffect(() => {
        if (accepted && !wasSuccessful.current) setDefaults();
        wasSuccessful.current = accepted;
    }, [accepted, setDefaults]);
    useLeaveProtection(isDirty, processing);
}
