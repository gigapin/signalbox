/** Exercise responsive shell lifecycle; native modal focus containment is verified separately in a browser. */
import { router } from '@inertiajs/react';
import { act, cleanup, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useEffect, useState } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import AppLayout from '../../resources/js/components/app/AppLayout';
import { DESKTOP_NAVIGATION_QUERY } from '../../resources/js/components/app/useDesktopNavigation';
import { renderInertia } from './renderInertia';

/** Current synthetic breakpoint, read through the production useSyncExternalStore subscription. */
let desktop = false;
/** Breakpoint listener lifetimes, allowing explicit resize/zoom transitions without sleeping. */
const listeners = new Set<() => void>();
/** Preserve native/provided prototype descriptors; jsdom does not implement a complete modal top layer. */
const originalShow = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
/** Preserve close independently so no test double escapes this file. */
const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
/** An external authorization signal, deliberately independent of background dialog interaction. */
let revoke: () => void = () => undefined;

beforeEach(() => {
    desktop = false;
    listeners.clear();
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            get matches() {
                return desktop;
            },
            media: query,
            addEventListener: (_type: string, listener: () => void) => {
                listeners.add(listener);
            },
            removeEventListener: (_type: string, listener: () => void) => {
                listeners.delete(listener);
            },
        })),
    );
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
        configurable: true,
        value: vi.fn(function (this: HTMLDialogElement): void {
            this.setAttribute('open', '');
        }),
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {
        configurable: true,
        value: vi.fn(function (this: HTMLDialogElement): void {
            this.removeAttribute('open');
            this.dispatchEvent(new Event('close'));
        }),
    });
});

afterEach(() => {
    cleanup();
    if (originalShow) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', originalShow);
    else Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal');
    if (originalClose) Object.defineProperty(HTMLDialogElement.prototype, 'close', originalClose);
    else Reflect.deleteProperty(HTMLDialogElement.prototype, 'close');
    document.documentElement.style.overflow = '';
});

/** Render an authorized shell whose access can be revoked externally while the dialog is open. */
function LayoutFixture() {
    const [denied, setDenied] = useState(false);
    useEffect(() => {
        revoke = () => setDenied(true);
        return () => {
            revoke = () => undefined;
        };
    }, []);
    return (
        <AppLayout
            title="Monitor details"
            team={{ id: 12, name: 'Synthetic team' }}
            section="monitors"
            accessDenied={denied}
        >
            <label htmlFor="draft">Draft</label>
            <input id="draft" defaultValue="Keep this note" />
        </AppLayout>
    );
}

/** Mount with a verified identity; team selector stays a single header-owned form. */
function mountLayout() {
    return renderInertia('Signalbox', LayoutFixture, {
        auth: { user: { name: 'Viewer', email: 'viewer@example.test' } },
        teamNavigation: {
            teams: [{ id: 12, name: 'Synthetic team', role: 'viewer' }],
            selectedTeamId: 12,
        },
    });
}

/** Simulate the browser crossing the shared CSS/media-query boundary. */
function resize(toDesktop: boolean): void {
    act(() => {
        desktop = toDesktop;
        for (const listener of listeners) listener();
    });
}

test('starts closed on mobile and mounts no hidden sidebar links in the active tab sequence', () => {
    mountLayout();
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
    expect(screen.queryByRole('navigation', { name: 'Team navigation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(window.matchMedia(DESKTOP_NAVIGATION_QUERY).media).toBe(DESKTOP_NAVIGATION_QUERY);
    expect(screen.getByRole('combobox', { name: 'Team' })).toBeVisible();
});

test('opens through the keyboard as a named modal, focuses Close and exposes one current section', async () => {
    const user = userEvent.setup();
    mountLayout();
    const trigger = screen.getByRole('button', { name: 'Open navigation' });
    trigger.focus();
    await user.keyboard('{Enter}');
    const dialog = screen.getByRole('dialog', { name: 'Navigation' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAttribute('open');
    expect(screen.getByRole('button', { name: 'Close navigation' })).toHaveFocus();
    expect(document.documentElement.style.overflow).toBe('hidden');
    expect(within(dialog).getAllByRole('navigation', { name: 'Team navigation' })).toHaveLength(1);
    expect(within(dialog).getByRole('link', { current: 'page' })).toHaveAccessibleName('Monitors');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getAllByRole('combobox', { name: 'Team' })).toHaveLength(1);
});

test('native Escape cancellation closes and restores focus and prior scrolling styles', async () => {
    document.documentElement.style.overflow = 'clip';
    const user = userEvent.setup();
    mountLayout();
    const trigger = screen.getByRole('button', { name: 'Open navigation' });
    await user.click(trigger);
    const cancellation = new Event('cancel', { cancelable: true });
    fireEvent(screen.getByRole('dialog'), cancellation);
    expect(cancellation.defaultPrevented).toBe(true);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(document.documentElement.style.overflow).toBe('clip');
});

test('explicit Close preserves a page draft and removes the scroll lock', async () => {
    const user = userEvent.setup();
    mountLayout();
    await user.type(screen.getByLabelText('Draft'), ' modified');
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    await user.click(screen.getByRole('button', { name: 'Close navigation' }));
    expect(screen.getByLabelText('Draft')).toHaveValue('Keep this note modified');
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveFocus();
    expect(document.documentElement.style.overflow).toBe('');
});

test('closes on same-tab navigation then focuses the destination heading on success', async () => {
    const user = userEvent.setup();
    let callbacks: Parameters<typeof router.visit>[1];
    const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        callbacks = options;
    });
    mountLayout();
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    await user.click(screen.getByRole('link', { name: 'Incidents' }));
    expect(visit).toHaveBeenCalledWith('/teams/12/incidents', expect.any(Object));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveFocus();
    act(() => {
        void callbacks?.onSuccess?.({} as Parameters<NonNullable<typeof callbacks.onSuccess>>[0]);
    });
    await vi.waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
});

test('retains the current dialog for a modified click that opens another tab', async () => {
    const user = userEvent.setup();
    vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    mountLayout();
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    const link = screen.getByRole('link', { name: 'Incidents' });
    link.addEventListener('click', (event) => event.preventDefault());
    fireEvent.click(link, { ctrlKey: true });
    expect(screen.getByRole('dialog')).toHaveAttribute('open');
});

test('crossing to desktop closes the dialog, removes its trigger and restores focus to visible content', async () => {
    const user = userEvent.setup();
    mountLayout();
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    resize(true);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open navigation' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('navigation', { name: 'Team navigation' })).toHaveLength(1);
    expect(screen.getByRole('main')).toHaveFocus();
    expect(document.documentElement.style.overflow).toBe('');
    resize(false);
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
    expect(screen.queryByRole('navigation', { name: 'Team navigation' })).not.toBeInTheDocument();
});

test('authorization denial removes an open dialog without leaving focus on a removed control', async () => {
    const user = userEvent.setup();
    mountLayout();
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    act(revoke);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Open navigation' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveFocus();
    expect(document.documentElement.style.overflow).toBe('');
});

test('unmount cleans media listeners, an open dialog and its root scroll lock', async () => {
    const user = userEvent.setup();
    const view = mountLayout();
    await user.click(screen.getByRole('button', { name: 'Open navigation' }));
    expect(listeners.size).toBe(1);
    view.unmount();
    expect(listeners.size).toBe(0);
    expect(document.querySelector('dialog[open]')).toBeNull();
    expect(document.documentElement.style.overflow).toBe('');
});

test('moving focus from Account to mobile navigation closes the account disclosure before modal Escape', async () => {
    const user = userEvent.setup();
    mountLayout();
    await user.click(screen.getByRole('button', { name: 'Account menu' }));
    screen.getByRole('button', { name: 'Open navigation' }).focus();
    await user.keyboard('{Enter}');
    expect(
        screen.queryByRole('navigation', { name: 'Account navigation' }),
    ).not.toBeInTheDocument();
    fireEvent(screen.getByRole('dialog'), new Event('cancel', { cancelable: true }));
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveFocus();
});
