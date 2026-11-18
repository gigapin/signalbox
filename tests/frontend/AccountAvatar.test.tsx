/** Verify Unicode initials and the decorative circular avatar independent of account transport. */
import { render } from '@testing-library/react';
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
