/** Provide the mobile team navigation as a native modal dialog, isolated from page form state. */
import { useEffect, useId, useRef, useState } from 'react';
import Button from '../ui/Button';
import TeamLinks from './TeamLinks';
import type { TeamSection } from './navigation';

/** Explicit route identity plus the content landmark used when the trigger disappears. */
interface MobileNavigationProps {
    /** Authorized route team identifier. */
    teamId: number;
    /** Current page family. */
    section: TeamSection;
    /** Existing main landmark; fallback focus for navigation, suspension and desktop transitions. */
    mainId: string;
}

/**
 * @param props Current route scope and main landmark.
 * @returns A trigger and one native dialog; the browser owns modal focus containment and inert background.
 */
export default function MobileNavigation({ teamId, section, mainId }: MobileNavigationProps) {
    const dialogId = useId();
    const titleId = useId();
    const dialog = useRef<HTMLDialogElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const previousOverflow = useRef<string | null>(null);
    const [open, setOpen] = useState(false);

    /** Restore exactly the prior root scrolling style, including a pre-existing caller-owned lock. */
    function restoreScroll(): void {
        if (previousOverflow.current === null) return;
        document.documentElement.style.overflow = previousOverflow.current;
        previousOverflow.current = null;
    }

    useEffect(() => {
        const element = dialog.current;
        return () => {
            const wasOpen = element?.open;
            if (wasOpen) element.close();
            restoreScroll();
            // The mobile trigger may be disappearing at a breakpoint change or access denial.
            if (wasOpen) document.getElementById(mainId)?.focus();
        };
    }, [mainId]);

    /** Open in the browser's modal top layer and place focus on the explicit close control. */
    function show(): void {
        const element = dialog.current;
        if (!element || element.open) return;
        element.showModal();
        previousOverflow.current = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        setOpen(true);
        closeButton.current?.focus();
    }

    /** Close without cancelling any team-selection work; restore either the trigger or content landmark. */
    function dismiss(toTrigger = true): void {
        if (!dialog.current?.open) return;
        dialog.current.close();
        restoreScroll();
        setOpen(false);
        if (toTrigger) trigger.current?.focus();
        else document.getElementById(mainId)?.focus();
    }

    return (
        <>
            <Button
                ref={trigger}
                variant="secondary"
                aria-haspopup="dialog"
                aria-controls={dialogId}
                aria-expanded={open}
                onClick={show}
            >
                Open navigation
            </Button>
            <dialog
                ref={dialog}
                id={dialogId}
                className="ui-mobile-navigation"
                aria-labelledby={titleId}
                aria-modal="true"
                onCancel={(event) => {
                    event.preventDefault();
                    dismiss();
                }}
                onClose={() => {
                    if (dialog.current?.open) return;
                    restoreScroll();
                    setOpen(false);
                }}
            >
                <div className="ui-mobile-navigation-heading">
                    <h2 id={titleId} className="ui-section-title">
                        Navigation
                    </h2>
                    <Button ref={closeButton} variant="secondary" onClick={() => dismiss()}>
                        Close navigation
                    </Button>
                </div>
                <TeamLinks teamId={teamId} section={section} onNavigate={() => dismiss(false)} />
            </dialog>
        </>
    );
}
