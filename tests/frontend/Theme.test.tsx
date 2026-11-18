/** Verify confirmed theme switching, failed writes and anonymous page controls. */
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import { ThemeProvider, ThemeToggle } from '../../resources/js/components/appearance/Theme';
import AppLayout from '../../resources/js/components/app/AppLayout';
import PrivateContextLayout from '../../resources/js/components/app/PrivateContextLayout';
import Home from '../../resources/js/pages/Home';
import { renderInertia } from './renderInertia';

/** Mount the real preference boundary under Inertia context. */
function Header() {
    return (
        <ThemeProvider>
            <ThemeToggle />
        </ThemeProvider>
    );
}

test('saves light then dark with CSRF and no navigation', async () => {
    document.cookie = 'XSRF-TOKEN=synthetic';
    const fetch = vi
        .mocked(globalThis.fetch)
        .mockResolvedValueOnce(new Response(JSON.stringify({ theme: 'light' })))
        .mockResolvedValueOnce(new Response(JSON.stringify({ theme: 'dark' })));
    const user = userEvent.setup();
    renderInertia('Signalbox', Header, { uiTheme: 'dark' });
    await user.click(screen.getByRole('button', { name: 'Light mode' }));
    expect(document.documentElement).toHaveAttribute('data-ui-theme', 'light');
    expect(fetch).toHaveBeenCalledWith(
        '/preferences/theme',
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ theme: 'light' }) }),
    );
    await user.click(screen.getByRole('button', { name: 'Dark mode' }));
    expect(document.documentElement).toHaveAttribute('data-ui-theme', 'dark');
});

test('keeps the confirmed theme after a refused write and permits retry', async () => {
    document.cookie = 'XSRF-TOKEN=synthetic';
    vi.mocked(globalThis.fetch).mockResolvedValue(new Response('{}', { status: 419 }));
    const user = userEvent.setup();
    renderInertia('Signalbox', Header, { uiTheme: 'dark' });
    await user.click(screen.getByRole('button', { name: 'Light mode' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Theme could not be saved');
    expect(document.documentElement).toHaveAttribute('data-ui-theme', 'dark');
    expect(screen.getByRole('button', { name: 'Light mode' })).toBeEnabled();
});

test('welcome has no theme switch', () => {
    renderInertia('Signalbox', Home, { uiTheme: 'light' });
    expect(screen.queryByRole('button', { name: /mode/i })).not.toBeInTheDocument();
});

test('disables the switch during saving and ignores the account theme on public pages', async () => {
    document.cookie = 'XSRF-TOKEN=synthetic';
    let finish: ((value: Response) => void) | undefined;
    vi.mocked(globalThis.fetch).mockImplementation(
        () =>
            new Promise<Response>((resolve) => {
                finish = resolve;
            }),
    );
    const user = userEvent.setup();
    const view = renderInertia('Signalbox', Header, { uiTheme: 'dark' });
    await user.click(screen.getByRole('button', { name: 'Light mode' }));
    expect(screen.getByRole('button', { name: 'Light mode' })).toBeDisabled();
    finish?.(new Response(JSON.stringify({ theme: 'light' })));
    await screen.findByRole('button', { name: 'Dark mode' });
    view.unmount();
    renderInertia('Signalbox', Header, { uiTheme: null });
    expect(document.documentElement).not.toHaveAttribute('data-ui-theme');
});

/** Render the actual workspace headers inside the shared appearance provider. */
function WorkspaceHeader({ account = false }: { account?: boolean }) {
    return (
        <ThemeProvider>
            {account ? (
                <PrivateContextLayout title="Account">
                    <p>Content</p>
                </PrivateContextLayout>
            ) : (
                <AppLayout title="Dashboard" team={{ id: 7, name: 'Example' }} section="dashboard">
                    <p>Content</p>
                </AppLayout>
            )}
        </ThemeProvider>
    );
}

test.each([false, true])(
    'places the icon-only theme switch directly before the final profile control (account=%s)',
    (account) => {
        renderInertia('Signalbox', () => <WorkspaceHeader account={account} />, {
            uiTheme: 'dark',
            auth: { user: { name: 'Example user', email: 'example@test.invalid' } },
        });
        const theme = screen.getByRole('button', { name: 'Light mode' });
        const profile = screen.getByRole('button', { name: 'Account menu' });
        expect(theme.textContent).toBe('');
        expect(theme.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
        const header = theme.closest('header')!;
        expect(theme.parentElement?.nextElementSibling).toBe(profile.parentElement);
        expect(header.lastElementChild).toBe(profile.parentElement);
    },
);
