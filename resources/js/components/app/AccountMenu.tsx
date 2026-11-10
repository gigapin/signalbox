/** Keep account navigation and explicit logout separate from team resource navigation. */
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import Button from '../ui/Button';

/** Minimal identity for the account disclosure; no tenant or credential state is read here. */
interface AccountMenuProps {
    /** Current account's safe display name. */
    name: string;
    /** Server-projected flag controlling only visibility; the admin panel reauthorizes every request. */
    isPlatformAdmin: boolean;
}

/**
 * @param props Current identity and operator link visibility.
 * @returns A keyboard disclosure with native links and one caller-triggered logout POST.
 */
export default function AccountMenu({ name, isPlatformAdmin }: AccountMenuProps) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const logout = useForm<Record<string, never>>({});

    useEffect(() => {
        if (!open) return;

        /** Close an open disclosure with Escape and restore its trigger's focus. */
        function escape(event: KeyboardEvent): void {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            setOpen(false);
            trigger.current?.focus();
        }

        /** Close outside the disclosure while preserving focus at the user's chosen destination. */
        function outside(event: PointerEvent): void {
            if (event.target instanceof Node && !root.current?.contains(event.target)) {
                setOpen(false);
            }
        }

        document.addEventListener('keydown', escape);
        document.addEventListener('pointerdown', outside);

        return () => {
            document.removeEventListener('keydown', escape);
            document.removeEventListener('pointerdown', outside);
        };
    }, [open]);

    /** Submit only the session logout; neither navigation nor reconnection replays it. */
    function signOut(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (logout.processing) return;
        logout.post('/logout');
    }

    return (
        <div className="ui-account" ref={root}>
            <Button
                ref={trigger}
                variant="secondary"
                aria-label="Account menu"
                aria-expanded={open}
                aria-controls={menuId}
                onClick={() => setOpen(!open)}
            >
                {name}
            </Button>
            {open && (
                <div id={menuId} className="ui-account-panel">
                    <nav aria-label="Account navigation">
                        <Link
                            className="ui-shell-link"
                            href="/account"
                            onClick={() => setOpen(false)}
                        >
                            Your account
                        </Link>
                        <Link
                            className="ui-shell-link"
                            href="/teams"
                            onClick={() => setOpen(false)}
                        >
                            Your teams
                        </Link>
                        <Link
                            className="ui-shell-link"
                            href="/account/security"
                            onClick={() => setOpen(false)}
                        >
                            Account security
                        </Link>
                        {isPlatformAdmin && (
                            <Link
                                className="ui-shell-link"
                                href="/admin/teams"
                                onClick={() => setOpen(false)}
                            >
                                Admin
                            </Link>
                        )}
                    </nav>
                    <form onSubmit={signOut}>
                        <Button
                            type="submit"
                            variant="secondary"
                            busy={logout.processing}
                            busyLabel="Signing out…"
                        >
                            Sign out
                        </Button>
                    </form>
                </div>
            )}
        </div>
    );
}
