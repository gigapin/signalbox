/** Verify account prefill, optional fields and explicit save confirmation without real transport. */
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { expect, test, vi } from 'vitest';
import Overview from '../../resources/js/pages/Account/Overview';
import { renderInertia } from './renderInertia';
/** Synthetic private identity and explicit profile projection. */
const props = {
    auth: { user: { name: 'Giga Prog', email: 'giga@example.test' } },
    profile: { first_name: 'Giga Prog', email: 'giga@example.test' },
    countries: ['IT', 'GB'],
};
test('replaces the old cards with optional profile fields and registration email', () => {
    renderInertia('Signalbox', Overview, props);
    expect(screen.getByLabelText('Email')).toHaveValue('giga@example.test');
    expect(screen.getByLabelText('First name')).toHaveValue('Giga Prog');
    expect(screen.getByLabelText('Last name')).not.toBeRequired();
    expect(screen.getByLabelText('Logo / avatar')).toHaveAccessibleDescription(
        'Optional JPEG, PNG or WebP. Maximum 2 MiB and 512 × 512 pixels.',
    );
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    expect(screen.queryByRole('heading', { name: 'Team access' })).not.toBeInTheDocument();
});
test('cancels without losing changes and sends one confirmed multipart save', async () => {
    const user = userEvent.setup();
    const confirm = vi
        .spyOn(window, 'confirm')
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Overview, props);
    await user.type(screen.getByLabelText('Phone'), '+39 0123');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(post).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Phone')).toHaveValue('+39 0123');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(post).toHaveBeenCalledOnce();
});
