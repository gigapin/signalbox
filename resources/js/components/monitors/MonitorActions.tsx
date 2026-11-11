/** Coordinate authorized monitor transitions with inline confirmation and a single owned mutation. */
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { useFormProtection, useNavigationGuard } from '../app/NavigationGuard';
import Button from '../ui/Button';
import Panel from '../ui/Panel';
import { useMonitorMutation } from '../../hooks/useMonitorMutation';
import MutationFeedback from './MutationFeedback';
import type { MonitorDetail } from './types';
import { monitorPath, withMonitorListReturn } from './urls';

/** Server hints only; every write rechecks the policy and plan limits. */
export interface MonitorPermissions {
    /** Edit stored configuration. */
    update: boolean;
    /** Permanently remove this monitor. */
    delete: boolean;
    /** Pause active scheduling. */
    pause: boolean;
    /** Resume a monitor within quota. */
    resume: boolean;
}

/** Actions always belong to the live route monitor and its authorized return context. */
interface ActionsProps {
    /** Authorized route team. */
    teamId: number;
    /** Latest safe live projection. */
    monitor: MonitorDetail;
    /** Latest policy hints. */
    can: MonitorPermissions;
    /** Server-normalized list context. */
    returnTo: string;
}

/** Available monitor transitions; no payload varies by action. */
type Transition = 'pause' | 'resume' | 'delete';

/** Visible transition verbs shared by the confirmation headings. */
const TRANSITION_LABELS: Record<Transition, string> = {
    pause: 'Pause',
    resume: 'Resume',
    delete: 'Delete',
};

/** Confirmed domain effects and server-side checks, without promising incident resolution or quota admission. */
const TRANSITION_EXPLANATIONS: Record<Transition, string> = {
    pause: 'Scheduled checks stop while paused. Existing incidents are not resolved by pausing.',
    resume: 'Scheduled checks resume. The server will recheck your current plan limits.',
    delete: 'This permanently removes the monitor and its related data. This cannot be undone.',
};

/**
 * @param props Live monitor, policy hints and return context.
 * @returns Explicit confirmations, pending/refused/uncertain feedback and authorized controls.
 */
export default function MonitorActions({ teamId, monitor, can, returnTo }: ActionsProps) {
    const form = useForm<Record<string, never>>({});
    useFormProtection(form);
    const mutation = useMonitorMutation();
    const guard = useNavigationGuard();
    const [choice, setChoice] = useState<Transition | null>(null);
    const cancelButton = useRef<HTMLButtonElement>(null);
    const trigger = useRef<HTMLButtonElement | null>(null);
    const confirmId = useId();
    const path = monitorPath(teamId, monitor.id);
    const allowed = {
        pause: can.pause && monitor.state === 'active',
        resume: can.resume && monitor.state === 'paused' && monitor.withinQuota,
        delete: can.delete,
    };
    const selected = choice !== null && allowed[choice] ? choice : null;
    // A later grant must not reopen a confirmation invalidated by a live policy or state change.
    if (choice !== null && selected === null) setChoice(null);
    const locked = mutation.locked || form.processing || guard.busy;

    useEffect(() => {
        if (selected !== null) cancelButton.current?.focus();
    }, [selected]);

    /** Close only an unsent confirmation and restore focus to the originating control. */
    function cancel(): void {
        if (locked) return;
        setChoice(null);
        trigger.current?.focus();
    }

    /**
     * @param transition Requested action, checked again against the latest live hints.
     * @param element Trigger to restore after Cancel or Escape.
     */
    function choose(transition: Transition, element: HTMLButtonElement): void {
        if (locked || !allowed[transition]) return;
        trigger.current = element;
        setChoice(transition);
    }

    /** Dispatch exactly one empty-payload mutation after the inline confirmation. */
    function confirm(): void {
        if (selected === null || locked) return;
        const action = selected === 'delete' ? path : `${path}/${selected}`;
        const destination = withMonitorListReturn(action, teamId, returnTo);
        if (
            mutation.run(destination, (options) => {
                if (selected === 'delete') form.delete(destination, options);
                else form.post(destination, options);
            })
        )
            setChoice(null);
    }

    return (
        <div className="ui-monitor-action-stack mb-6">
            <div className="ui-actions" role="group" aria-label="Monitor actions">
                {can.update && (
                    <Link
                        className="ui-button"
                        data-variant="secondary"
                        href={withMonitorListReturn(`${path}/edit`, teamId, returnTo)}
                    >
                        Edit monitor
                    </Link>
                )}
                {allowed.pause && (
                    <Button
                        variant="secondary"
                        disabled={locked}
                        aria-expanded={selected === 'pause'}
                        aria-controls={selected === 'pause' ? confirmId : undefined}
                        onClick={(event) => choose('pause', event.currentTarget)}
                    >
                        Pause monitor
                    </Button>
                )}
                {monitor.state === 'paused' && can.resume && (
                    <Button
                        variant="secondary"
                        disabled={locked || !monitor.withinQuota}
                        aria-expanded={selected === 'resume'}
                        aria-controls={selected === 'resume' ? confirmId : undefined}
                        onClick={(event) => choose('resume', event.currentTarget)}
                    >
                        Resume monitor
                    </Button>
                )}
                {allowed.delete && (
                    <Button
                        variant="danger"
                        disabled={locked}
                        aria-expanded={selected === 'delete'}
                        aria-controls={selected === 'delete' ? confirmId : undefined}
                        onClick={(event) => choose('delete', event.currentTarget)}
                    >
                        Delete monitor
                    </Button>
                )}
            </div>
            {selected !== null && (
                <div
                    id={confirmId}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            cancel();
                        }
                    }}
                >
                    <Panel title={`${TRANSITION_LABELS[selected]} ${monitor.name}?`}>
                        <p>{TRANSITION_EXPLANATIONS[selected]}</p>
                        <div className="ui-actions">
                            <Button
                                ref={cancelButton}
                                variant="secondary"
                                onClick={cancel}
                                disabled={locked}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant={selected === 'delete' ? 'danger' : 'primary'}
                                disabled={locked}
                                onClick={confirm}
                            >
                                Confirm {selected}
                            </Button>
                        </div>
                    </Panel>
                </div>
            )}
            <MutationFeedback
                phase={mutation.phase}
                reviewUrl={withMonitorListReturn(path, teamId, returnTo)}
                success="Monitor change confirmed"
                onReviewed={mutation.acknowledge}
            />
            {Object.entries(form.errors).map(([key, message]) => (
                <p key={key} role="alert">
                    {message}
                </p>
            ))}
        </div>
    );
}
