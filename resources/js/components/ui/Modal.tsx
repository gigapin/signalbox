/** Provide a controlled native modal with focus restoration and stable background scrolling. */
import { useEffect, useId, useRef, type ReactNode } from 'react';
import Button from './Button';

/** Caller-owned visibility, content and permission to close the modal. */
interface ModalProps {
    /** Whether the caller requests an open native dialog. */
    open: boolean;
    /** Visible modal heading and accessible dialog name. */
    title: string;
    /** Request closure without deciding whether caller-owned drafts should be discarded. */
    onRequestClose: () => void;
    /** Prevent Escape and close-button activation while a request is pending. */
    closeDisabled?: boolean;
    /** Mounted content is retained on ordinary close to preserve local drafts. */
    children: ReactNode;
}

/**
 * @param props Controlled visibility, heading, pending guard and persistent content.
 * @returns A native focus-containing dialog, without replacing the caller's domain lifecycle.
 */
export default function Modal({
    open,
    title,
    onRequestClose,
    closeDisabled = false,
    children,
}: ModalProps) {
    const titleId = useId();
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;
        if (!open || !element) return;
        const origin = document.activeElement;
        const root = document.documentElement;
        const overflow = root.style.overflow;
        const gutter = root.style.scrollbarGutter;
        root.style.scrollbarGutter = 'stable';
        root.style.overflow = 'hidden';
        element.showModal();
        element.querySelector<HTMLElement>('form input, form select, form textarea')?.focus();

        return () => {
            if (element.open) element.close();
            root.style.overflow = overflow;
            root.style.scrollbarGutter = gutter;
            if (origin instanceof HTMLElement && origin.isConnected)
                origin.focus({ preventScroll: true });
        };
    }, [open]);

    return (
        <dialog
            ref={dialog}
            className="ui-theme ui-modal"
            aria-labelledby={titleId}
            aria-modal="true"
            onCancel={(event) => {
                event.preventDefault();
                if (!closeDisabled) onRequestClose();
            }}
        >
            <div className="ui-modal-heading">
                <h2 id={titleId} className="ui-section-title">
                    {title}
                </h2>
                <Button variant="secondary" disabled={closeDisabled} onClick={onRequestClose}>
                    Close
                </Button>
            </div>
            {children}
        </dialog>
    );
}
