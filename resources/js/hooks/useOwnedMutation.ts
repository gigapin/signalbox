/** Own one foreground mutation until its finish callback; never infer rollback from a missing response. */
import { router, type InertiaFormProps } from '@inertiajs/react';
import { useContext, useEffect, useRef, useState } from 'react';
import { TeamAccessContext } from '../components/app/TeamAccessContext';
import { useLeaveProtection } from '../components/app/NavigationGuard';
import { isTransportFailure } from '../requestFailures';

/** The form adapter's installed visit callbacks, without duplicating Inertia's protocol. */
type MutationOptions = NonNullable<Parameters<InertiaFormProps<Record<string, never>>['post']>[1]>;

/** Visible outcome; uncertain remains blocked until the user explicitly reviews saved state. */
export type MutationPhase = 'idle' | 'pending' | 'refused' | 'uncertain' | 'success';

/** One owned attempt; callbacks from disposed or earlier attempts cannot change newer state. */
interface Attempt {
    /** Exact application-built URL, used only for event ownership and never rendered. */
    path: string;
    /** Whether a terminal response or failure was seen before finish. */
    settled: boolean;
    /** Cancellation affects transport only, never promises domain rollback. */
    cancel?: () => void;
}

/**
 * @param url Request URL reported by Axios; absent diagnostics are not treated as ownership.
 * @param path Current application-built mutation URL.
 * @returns Whether a global failure belongs to the active mutation, including its query string.
 */
function matches(url: unknown, path: string): boolean {
    if (typeof url !== 'string') return false;
    try {
        return new URL(url, location.href).href === new URL(path, location.href).href;
    } catch {
        return false;
    }
}

/** @returns Single-flight mutation dispatch, safe feedback and explicit acknowledgement without any replay. */
export function useOwnedMutation() {
    const deny = useContext(TeamAccessContext);
    const [phase, setPhase] = useState<MutationPhase>('idle');
    const active = useRef<Attempt | null>(null);
    const blocked = useRef(false);
    const mounted = useRef(true);
    useLeaveProtection(false, phase === 'pending');

    useEffect(() => {
        mounted.current = true;
        const stopInvalid = router.on('invalid', (event) => {
            const attempt = active.current;
            const response = event.detail.response;
            if (!attempt || !matches(response.config.url, attempt.path)) return;
            event.preventDefault();
            attempt.settled = true;
            if ([401, 403, 404].includes(response.status)) {
                deny();
                return;
            }
            const refused = [400, 405, 409, 413, 415, 419, 422, 425, 429].includes(response.status);
            setPhase(refused ? 'refused' : 'uncertain');
            blocked.current = !refused;
        });
        const stopException = router.on('exception', (event) => {
            const attempt = active.current;
            const error: unknown = event.detail.exception;
            if (!attempt || !isTransportFailure(error) || !('config' in error)) return;
            const config = error.config;
            if (
                typeof config !== 'object' ||
                config === null ||
                !('url' in config) ||
                !matches(config.url, attempt.path)
            )
                return;
            event.preventDefault();
            attempt.settled = true;
            blocked.current = true;
            setPhase('uncertain');
        });
        return () => {
            mounted.current = false;
            const attempt = active.current;
            active.current = null;
            attempt?.cancel?.();
            stopInvalid();
            stopException();
        };
    }, [deny]);

    /**
     * @param path Application-built mutation endpoint with server-normalized return context.
     * @param dispatch Submit through the caller's existing Inertia form without copying its payload.
     * @param refused Optional field-error focus callback, called only on confirmed validation refusal.
     * @param accepted Optional draft cleanup after confirmed acceptance.
     * @returns Whether a new mutation was started; duplicate and uncertain attempts remain blocked.
     */
    function run(
        path: string,
        dispatch: (options: MutationOptions) => void,
        refused?: () => void,
        accepted?: () => void,
    ): boolean {
        if (active.current || blocked.current) return false;
        const attempt: Attempt = { path, settled: false };
        active.current = attempt;
        setPhase('pending');

        /** Only the mounted owner may consume callbacks from this attempt. */
        const owns = () => mounted.current && active.current === attempt;
        try {
            dispatch({
                preserveScroll: true,
                onCancelToken: (token) => {
                    if (owns()) attempt.cancel = () => token.cancel();
                    else token.cancel();
                },
                onError: () => {
                    if (!owns()) return;
                    attempt.settled = true;
                    setPhase('refused');
                    refused?.();
                },
                onSuccess: () => {
                    if (!owns()) return;
                    attempt.settled = true;
                    setPhase('success');
                    accepted?.();
                },
                onCancel: () => {
                    if (!owns()) return;
                    attempt.settled = true;
                    blocked.current = true;
                    setPhase('uncertain');
                },
                onFinish: () => {
                    if (!owns()) return;
                    if (!attempt.settled) {
                        blocked.current = true;
                        setPhase('uncertain');
                    }
                    active.current = null;
                },
            });
        } catch (error) {
            active.current = null;
            blocked.current = true;
            setPhase('uncertain');
            throw error;
        }
        return true;
    }

    /** Unlock a later explicit attempt only after review; this never dispatches a request. */
    function acknowledge(): void {
        if (active.current) return;
        blocked.current = false;
        setPhase('idle');
    }

    return { phase, locked: phase === 'pending' || phase === 'uncertain', run, acknowledge };
}
