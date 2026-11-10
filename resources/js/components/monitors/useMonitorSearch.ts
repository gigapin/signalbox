/** Own one explicit GET search and keep draft filters after a refused or failed read. */
import { router } from '@inertiajs/react';
import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useLeaveProtection } from '../app/NavigationGuard';
import { TeamAccessContext } from '../app/TeamAccessContext';
import type { MonitorFilters } from './types';
import { listQuery, monitorsPath } from './urls';

/**
 * @param teamId Authorized route team.
 * @param filters Accepted filters for the mounted URL; the page remounts when its selection changes.
 * @returns Draft controls, single-flight pending state, safe feedback and explicit search/clear operations.
 */
export function useMonitorSearch(teamId: number, filters: MonitorFilters) {
    const deny = useContext(TeamAccessContext);
    const [draft, setDraft] = useState(filters);
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
    function navigate(next: MonitorFilters): void {
        if (active.current) return;
        active.current = true;
        const attempt = ++generation.current;
        setFailed(false);
        router.get(monitorsPath(teamId), listQuery(next), {
            preserveState: true,
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

    /** Explicitly clear all narrowing filters, beginning at page one. */
    function clear(): void {
        const empty = { q: '', type: null, status: null, state: null };
        setDraft(empty);
        navigate(empty);
    }

    return { draft, setDraft, loading, failed, navigate, clear };
}
