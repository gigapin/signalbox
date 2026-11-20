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
    const save = screen.getByRole('button', { name: 'Save changes' });
    expect(save).toBeDisabled();
    expect(
        screen.getByRole('heading', { name: 'Your account' }).closest('header'),
    ).toContainElement(save);
    expect(save).toHaveAttribute('form', screen.getByLabelText('First name').closest('form')?.id);
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

/** Team labels never supply missing personal fields, even when the display name resembles a team. */
test('keeps personal names separate from team identity', () => {
    renderInertia('Signalbox', Overview, {
        ...props,
        profile: { ...props.profile, first_name: null, last_name: null },
        currentTeam: { id: 1, name: 'Synthetic Alpha' },
    });
    expect(screen.getByLabelText('First name')).toHaveValue('');
    expect(screen.getByLabelText('Last name')).toHaveValue('');
});

/** Moving the visible submit to the heading retains implicit keyboard submission inside the form. */
test('submits the account form with Enter from a field', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    renderInertia('Signalbox', Overview, props);
    await user.type(screen.getByLabelText('Phone'), '+39 0123{Enter}');
    expect(post).toHaveBeenCalledOnce();
});
