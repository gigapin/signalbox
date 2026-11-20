/** Verify committed ranges, cancellation and tenant-safe download placement. */
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Reports from '../../resources/js/pages/Reports/Index';
import { renderInertia } from './renderInertia';
import { expect, test } from 'vitest';

/** Render an authorized page with stable server-owned bounds. */
function page() {
    renderInertia('Signalbox', Reports, {
        team: { id: 7, name: 'Synthetic team' },
        reportTimezone: 'Europe/Rome',
        reportCalendar: {
            min: '2026-01-01',
            max: '2026-06-25',
            from: '2026-05-01',
            to: '2026-05-31',
        },
    });
}

test('offers the previous complete month beside the Reports title', () => {
    page();
    expect(screen.getByRole('heading', { name: 'Reports' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Reports' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Download PDF' })).toHaveAttribute(
        'href',
        '/teams/7/reports/download?from=2026-05-01&to=2026-05-31',
    );
});

test('commits a single day only on Apply and discards a cancelled draft', async () => {
    page();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Report period/ }));
    const dialog = screen.getByRole('dialog', { name: 'Select report period' });
    expect(within(dialog).getByRole('button', { name: 'Apply' })).toBeDisabled();
    const day = within(dialog).getByRole('button', { name: /May 12th, 2026/ });
    await user.click(day);
    expect(within(dialog).getByRole('button', { name: 'Apply' })).toBeDisabled();
    await user.click(day);
    await user.click(within(dialog).getByRole('button', { name: 'Apply' }));
    expect(screen.getByRole('link', { name: 'Download PDF' })).toHaveAttribute(
        'href',
        '/teams/7/reports/download?from=2026-05-12&to=2026-05-12',
    );
    await user.click(screen.getByRole('button', { name: /Report period/ }));
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Select report period' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Download PDF' })).toHaveAttribute(
        'href',
        '/teams/7/reports/download?from=2026-05-12&to=2026-05-12',
    );
});

/** Selecting across months keeps both local endpoints inclusive. */
test('selects across month navigation and disables dates beyond yesterday', async () => {
    page();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Report period/ }));
    const dialog = screen.getByRole('dialog', { name: 'Select report period' });
    await user.click(within(dialog).getByRole('button', { name: /May 12th, 2026/ }));
    await user.click(within(dialog).getByRole('button', { name: /next month/i }));
    expect(within(dialog).getByRole('button', { name: /June 26th, 2026/ })).toBeDisabled();
    await user.click(within(dialog).getByRole('button', { name: /June 25th, 2026/ }));
    await user.click(within(dialog).getByRole('button', { name: 'Apply' }));
    expect(screen.getByRole('link', { name: 'Download PDF' })).toHaveAttribute(
        'href',
        '/teams/7/reports/download?from=2026-05-12&to=2026-06-25',
    );
});
