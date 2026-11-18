/** Verify Unicode initials and the decorative circular avatar independent of account transport. */
import { fireEvent, render } from '@testing-library/react';
import { expect, test } from 'vitest';
import AccountAvatar, { accountInitials } from '../../resources/js/components/app/AccountAvatar';

test.each([
    ['Giga Prog', 'GP'],
    ['  Giga   Prog  ', 'GP'],
    ['Giga Middle Prog', 'GP'],
    ['Giga', 'G'],
    ['Élodie Óscar', 'ÉÓ'],
    ['E\u0301lodie Prog', 'ÉP'],
    ['名字 用户', '名用'],
    ['', '?'],
    ['  ', '?'],
])('derives initials from %j', (name, initials) => {
    expect(accountInitials(name)).toBe(initials);
});

test('renders decorative initials without replacing the control accessible name', () => {
    const { container } = render(<AccountAvatar name="Giga Prog" />);
    expect(container.firstChild).toHaveTextContent('GP');
    expect(container.firstChild).toHaveAttribute('aria-hidden', 'true');
    expect(container.firstChild).toHaveClass('ui-account-avatar');
});

test('replaces initials with the private avatar and falls back after an image error', () => {
    const { container } = render(
        <AccountAvatar name="Giga Prog" avatarUrl="/account/avatar?v=synthetic" />,
    );
    const image = container.querySelector('img')!;
    expect(image).toHaveAttribute('src', '/account/avatar?v=synthetic');
    fireEvent.error(image);
    expect(container).toHaveTextContent('GP');
    expect(container.querySelector('img')).toBeNull();
});
