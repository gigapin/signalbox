/** Switch an account preference once, then navigate only after a checked server acknowledgement. */
import SelectField from '../ui/SelectField';
import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { csrfHeader } from '../csrf';
import Alert from '../ui/Alert';
import PageLoader from '../ui/PageLoader';
import Button from '../ui/Button';
import { useLeaveProtection, useNavigationGuard } from './NavigationGuard';
import type { TeamNavigationView } from './navigation';

/** Membership choices and caller-controlled suspension of private page content. */
interface TeamSwitcherProps {
    /** Commit selection directly without an adjacent submit button in workspace headers. */
    immediate?: boolean;
    /** Fresh explicit membership projection from a private page. */
    navigation: TeamNavigationView;
    /** Route team in team pages, valid preference in account pages, or null when not selected. */
    activeTeamId: number | null;
    /** Trusted local continuation after validating the server's canonical dashboard acknowledgement. */
    afterSwitch?: 'dashboard' | 'teams';
    /** Remove old resource content and dispose its effects before sending the switch request. */
    onSuspend?: () => void;
}

/** Stable safe feedback shared by malformed, timed-out and uncertain responses. */
const FAILURE =
    'The team change could not be confirmed. Check your current teams before trying again.';

/**
 * @param props Current memberships, active route/preference and private-content suspension callback.
 * @returns An explicit native selection form; failed requests are never automatically replayed.
 */
export default function TeamSwitcher({
    navigation,
    activeTeamId,
    onSuspend,
    afterSwitch = 'dashboard',
    immediate = false,
}: TeamSwitcherProps) {
    const guard = useNavigationGuard();
    const [choice, setChoice] = useState(
        navigation.teams.some((team) => team.id === activeTeamId) ? String(activeTeamId) : '',
    );
    const [phase, setPhase] = useState<'idle' | 'pending' | 'error'>('idle');
    useLeaveProtection(false, phase === 'pending');
    const admitting = useRef(false);
    const operation = useRef<AbortController | null>(null);
    const alive = useRef(false);
    const fieldId = useId();
    const busyDescriptionId = useId();

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            operation.current?.abort();
        };
    }, []);

    /** Check acknowledgement before the read-only navigation; unmount and aborted requests cannot navigate. */
    async function select(value: string): Promise<void> {
        const target = navigation.teams.find((team) => String(team.id) === value);
        if (
            !target ||
            target.id === activeTeamId ||
            operation.current ||
            admitting.current ||
            guard.busy ||
            phase !== 'idle'
        )
            return;

        admitting.current = true;
        const allowed = await guard.confirmLeave();
        if (!allowed || !alive.current || operation.current) {
            admitting.current = false;
            return;
        }

        const controller = new AbortController();
        operation.current = controller;
        setPhase('pending');
        onSuspend?.();
        const timeout = window.setTimeout(() => controller.abort(), 10_000);

        try {
            const response = await fetch(`/teams/${target.id}/select`, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfHeader(FAILURE),
                },
            });
            if (!response.ok || response.redirected) throw new Error(FAILURE);
            const body: unknown = await response.json();
            const destination = `/teams/${target.id}/dashboard`;
            if (
                typeof body !== 'object' ||
                body === null ||
                !('destination' in body) ||
                body.destination !== destination
            ) {
                throw new Error(FAILURE);
            }
            if (!alive.current) return;
            if (controller.signal.aborted) throw new Error(FAILURE);

            // Inertia may finish a successful visit before React unmounts the previous shell.
            let navigated = false;
            guard.runAuthorizedNavigation(() =>
                router.visit(afterSwitch === 'teams' ? '/teams' : destination, {
                    preserveState: false,
                    preserveScroll: false,
                    onSuccess: () => {
                        navigated = true;
                    },
                    onFinish: () => {
                        if (alive.current && !navigated) setPhase('error');
                    },
                }),
            );
        } catch {
            if (alive.current) setPhase('error');
        } finally {
            window.clearTimeout(timeout);
            admitting.current = false;
        }
    }

    if (phase === 'error') {
        return (
            <Alert title="Check team selection" tone="warning" announce="assertive">
                <p>{FAILURE}</p>
                <Link className="ui-link" href="/teams">
                    Check current teams
                </Link>
            </Alert>
        );
    }

    if (navigation.teams.length === 0) {
        return (
            <p>
                No accessible teams.{' '}
                <Link className="ui-link" href="/account">
                    Your account and invitations
                </Link>
            </p>
        );
    }

    return (
        <>
            <PageLoader active={phase === 'pending'} label="Switching team…" />
            <form
                className="ui-team-switcher"
                data-immediate={immediate || undefined}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!immediate) void select(choice);
                }}
                aria-busy={phase === 'pending'}
            >
                <SelectField
                    labelHidden
                    label="Team"
                    id={fieldId}
                    value={choice}
                    disabled={phase === 'pending' || (immediate && guard.busy)}
                    aria-describedby={immediate && guard.busy ? busyDescriptionId : undefined}
                    title={
                        immediate && guard.busy
                            ? 'Finish the current request before switching teams.'
                            : undefined
                    }
                    onChange={(event) => {
                        const value = event.target.value;
                        if (immediate) void select(value);
                        else setChoice(value);
                    }}
                >
                    <option value="" disabled>
                        Choose a team
                    </option>
                    {navigation.teams.map((team) => (
                        <option key={team.id} value={team.id}>
                            {team.name} · {team.role}
                        </option>
                    ))}
                </SelectField>
                {!immediate && (
                    <Button
                        type="submit"
                        variant="secondary"
                        busy={phase === 'pending'}
                        busyLabel="Switching team…"
                        aria-describedby={guard.busy ? busyDescriptionId : undefined}
                        title={
                            guard.busy
                                ? 'Finish the current request before switching teams.'
                                : undefined
                        }
                        disabled={guard.busy || choice === '' || choice === String(activeTeamId)}
                    >
                        Switch team
                    </Button>
                )}
                {guard.busy && (
                    <p id={busyDescriptionId} role="status" className="sr-only">
                        Finish the current request before switching teams.
                    </p>
                )}
            </form>
        </>
    );
}
