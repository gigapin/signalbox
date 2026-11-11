/** Coordinate one owned foreground GET without replaying mutations or retaining domain form values. */
import { router } from '@inertiajs/react';
import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useLeaveProtection } from '../components/app/NavigationGuard';
import { TeamAccessContext } from '../components/app/TeamAccessContext';
import { isTransportFailure } from '../requestFailures';

/** Only presentation behavior varies between filtered lists and metric controls. */
interface ReadPresentation {
    /** Preserve list components where the caller keys its accepted selection explicitly. */
    preserveState?: boolean;
    /** Keep the reader near metric controls after applying a new selection. */
    preserveScroll?: boolean;
}

/**
 * @param url URL attributed by the browser transport, never displayed or persisted.
 * @param owned Application-built URL of the current GET including its scalar query controls.
 * @returns Whether origin, path and decoded query agree; order and equivalent space encoding do not change ownership.
 */
function matchesRead(url: unknown, owned: string | null): boolean {
    if (typeof url !== 'string' || owned === null) return false;
    try {
        const candidate = new URL(url, location.href);
        const expected = new URL(owned, location.href);
        candidate.hash = '';
        expected.hash = '';
        candidate.searchParams.sort();
        expected.searchParams.sort();
        return candidate.href === expected.href;
    } catch {
        return false;
    }
}

/** @returns Owned GET lifecycle, busy guard, safe failure state and explicit navigation. */
export function useReadNavigation() {
    const deny = useContext(TeamAccessContext);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const active = useRef(false);
    const ownedUrl = useRef<string | null>(null);
    const generation = useRef(0);
    const mounted = useRef(true);
    const cancel = useRef<(() => void) | null>(null);
    useLeaveProtection(false, loading);

    const fail = useCallback(() => {
        if (!mounted.current || !active.current) return;
        active.current = false;
        ownedUrl.current = null;
        setLoading(false);
        setFailed(true);
    }, []);

    useEffect(() => {
        mounted.current = true;
        const stopException = router.on('exception', (event) => {
            const error: unknown = event.detail.exception;
            if (!active.current || !isTransportFailure(error) || !('config' in error)) return;
            const config = error.config;
            if (
                typeof config !== 'object' ||
                config === null ||
                !('url' in config) ||
                !matchesRead(config.url, ownedUrl.current)
            )
                return;
            event.preventDefault();
            fail();
        });
        const stopInvalid = router.on('invalid', (event) => {
            if (
                !active.current ||
                !matchesRead(event.detail.response.config?.url, ownedUrl.current)
            )
                return;
            event.preventDefault();
            if ([401, 403, 404].includes(event.detail.response.status)) {
                active.current = false;
                ownedUrl.current = null;
                setLoading(false);
                deny();
                return;
            }
            fail();
        });
        return () => {
            mounted.current = false;
            active.current = false;
            ownedUrl.current = null;
            cancel.current?.();
            cancel.current = null;
            stopException();
            stopInvalid();
        };
    }, [fail, deny]);

    /** Apply a bounded read once; GET navigation never retries itself or mutates a monitor. */
    function navigate(
        path: string,
        data: Record<string, string> = {},
        presentation: ReadPresentation = {},
    ): void {
        if (active.current) return;
        const target = new URL(path, location.href);
        for (const [key, value] of Object.entries(data)) target.searchParams.set(key, value);
        target.hash = '';
        ownedUrl.current = target.href;
        active.current = true;
        const attempt = ++generation.current;
        setFailed(false);
        router.get(path, data, {
            preserveState: presentation.preserveState ?? false,
            preserveScroll: presentation.preserveScroll ?? false,
            replace: false,
            onCancelToken: (token) => {
                if (mounted.current && generation.current === attempt)
                    cancel.current = () => token.cancel();
                else token.cancel();
            },
            onStart: () => {
                if (mounted.current && generation.current === attempt) setLoading(true);
            },
            onError: () => {
                if (generation.current === attempt) fail();
            },
            onCancel: () => {
                if (generation.current === attempt) fail();
            },
            onFinish: () => {
                if (generation.current !== attempt) return;
                active.current = false;
                ownedUrl.current = null;
                cancel.current = null;
                if (mounted.current) setLoading(false);
            },
        });
    }

    return { loading, failed, navigate };
}
