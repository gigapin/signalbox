/** Coordinate one owned foreground GET without replaying mutations or retaining domain form values. */
import { router } from '@inertiajs/react';
import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useLeaveProtection } from '../components/app/NavigationGuard';
import { TeamAccessContext } from '../components/app/TeamAccessContext';

/** Only presentation behavior varies between filtered lists and metric controls. */
interface ReadPresentation {
    /** Preserve list components where the caller keys its accepted selection explicitly. */
    preserveState?: boolean;
    /** Keep the reader near metric controls after applying a new selection. */
    preserveScroll?: boolean;
}

/** @returns Owned GET lifecycle, busy guard, safe failure state and explicit navigation. */
export function useReadNavigation() {
    const deny = useContext(TeamAccessContext);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const active = useRef(false);
    const generation = useRef(0);
    const mounted = useRef(true);
    const cancel = useRef<(() => void) | null>(null);
    useLeaveProtection(false, loading);

    const fail = useCallback(() => {
        if (!mounted.current || !active.current) return;
        active.current = false;
        setLoading(false);
        setFailed(true);
    }, []);

    useEffect(() => {
        mounted.current = true;
        const stopException = router.on('exception', (event) => {
            if (!active.current) return;
            event.preventDefault();
            fail();
        });
        const stopInvalid = router.on('invalid', (event) => {
            if (!active.current) return;
            event.preventDefault();
            if ([401, 403, 404].includes(event.detail.response.status)) {
                active.current = false;
                deny();
                return;
            }
            fail();
        });
        return () => {
            mounted.current = false;
            active.current = false;
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
                cancel.current = null;
                if (mounted.current) setLoading(false);
            },
        });
    }

    return { loading, failed, navigate };
}
