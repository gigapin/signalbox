/** Switch an account preference once, then navigate only after a checked server acknowledgement. */
import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { csrfHeader } from '../csrf';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import { useLeaveProtection, useNavigationGuard } from './NavigationGuard';
import type { TeamNavigationView } from './navigation';

/** Membership choices and caller-controlled suspension of private page content. */
interface TeamSwitcherProps {
    /** Fresh explicit membership projection from a private page. */
    navigation: TeamNavigationView;
    /** Route team in team pages, valid preference in account pages, or null when not selected. */
    activeTeamId: number | null;
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
export default function TeamSwitcher({ navigation, activeTeamId, onSuspend }: TeamSwitcherProps) {
    const guard = useNavigationGuard();
    const [choice, setChoice] = useState(
        navigation.teams.some((team) => team.id === activeTeamId) ? String(activeTeamId) : '',
    );
    const [phase, setPhase] = useState<'idle' | 'pending' | 'error'>('idle');
    useLeaveProtection(false, phase === 'pending');
    const operation = useRef<AbortController | null>(null);
    const alive = useRef(false);
    const fieldId = useId();

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            operation.current?.abort();
        };
    }, []);

    /** Check acknowledgement before the read-only navigation; unmount and aborted requests cannot navigate. */
    async function select(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        const target = navigation.teams.find((team) => String(team.id) === choice);
        if (!target || target.id === activeTeamId || operation.current || phase !== 'idle') return;

        if (!(await guard.confirmLeave()) || !alive.current || operation.current) return;

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

            guard.runAuthorizedNavigation(() =>
                router.visit(destination, {
                    preserveState: false,
                    preserveScroll: false,
                    onFinish: () => {
                        if (alive.current) setPhase('error');
                    },
                }),
            );
        } catch {
            if (alive.current) setPhase('error');
        } finally {
            window.clearTimeout(timeout);
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
        <form
            className="ui-team-switcher"
            onSubmit={(event) => {
                void select(event);
            }}
            aria-busy={phase === 'pending'}
        >
            <label htmlFor={fieldId} className="ui-label">
                Team
            </label>
            <select
                id={fieldId}
                className="ui-input"
                value={choice}
                disabled={phase === 'pending'}
                onChange={(event) => setChoice(event.target.value)}
            >
                <option value="" disabled>
                    Choose a team
                </option>
                {navigation.teams.map((team) => (
                    <option key={team.id} value={team.id}>
                        {team.name} · {team.role}
                    </option>
                ))}
            </select>
            <Button
                type="submit"
                variant="secondary"
                busy={phase === 'pending'}
                busyLabel="Switching team…"
                disabled={guard.busy || choice === '' || choice === String(activeTeamId)}
            >
                Switch team
            </Button>
            {guard.busy && <p role="status">Finish the current request before switching teams.</p>}
            {phase === 'pending' && <p role="status">Switching team…</p>}
        </form>
    );
}
