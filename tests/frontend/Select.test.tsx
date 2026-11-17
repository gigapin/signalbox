/** Verify below-field selection without changing the native form contract or existing controlled values. */
import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Select from '../../resources/js/components/ui/Select';

/** Render a controlled field and preserve its native form value. */
function Fixture({ changed = () => undefined }: { changed?: (value: string) => void }) {
    const [value, setValue] = useState('alpha');
    return (
        <form aria-label="Example">
            <label htmlFor="choice">Service</label>
            <Select
                id="choice"
                name="service"
                value={value}
                onChange={(event) => {
                    setValue(event.target.value);
                    changed(event.target.value);
                }}
            >
                <option value="alpha">Alpha</option>
                <option value="blocked" disabled>
                    Blocked
                </option>
                <option value="beta">Beta</option>
                <option value="gamma">Gamma</option>
            </Select>
            <button type="button">Next</button>
        </form>
    );
}

test('opens below the field and clicking an option updates the displayed and submitted value once', async () => {
    const user = userEvent.setup();
    const changed = vi.fn();
    render(<Fixture changed={changed} />);
    const select = screen.getByRole('combobox', { name: 'Service' });
    vi.spyOn(select, 'getBoundingClientRect').mockReturnValue({
        x: 30,
        y: 100,
        top: 100,
        bottom: 144,
        left: 30,
        right: 330,
        width: 300,
        height: 44,
        toJSON: () => ({}),
    });
    await user.click(select);
    const picker = screen.getByRole('listbox', { name: 'Service' });
    expect(select).toHaveAttribute('aria-expanded', 'true');
    expect(document.querySelector('style')?.textContent).toContain('top:150px');
    await user.click(within(picker).getByRole('option', { name: 'Beta' }));
    expect(select).toHaveValue('beta');
    expect(select).toHaveDisplayValue('Beta');
    expect(
        new FormData(screen.getByRole<HTMLFormElement>('form', { name: 'Example' })).get('service'),
    ).toBe('beta');
    expect(changed).toHaveBeenCalledExactlyOnceWith('beta');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(select).toHaveFocus();
});

test('supports keyboard exploration, skips disabled options and commits with Enter', async () => {
    const user = userEvent.setup();
    render(<Fixture />);
    const select = screen.getByRole('combobox');
    select.focus();
    await user.keyboard('{ArrowDown}{ArrowDown}');
    expect(select).toHaveValue('alpha');
    expect(select).toHaveAttribute(
        'aria-activedescendant',
        within(screen.getByRole('listbox')).getByRole('option', { name: 'Beta' }).id,
    );
    await user.keyboard('{Enter}');
    expect(select).toHaveValue('beta');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
});

test('Escape cancels exploration and outside interaction dismisses without changing selection', async () => {
    const user = userEvent.setup();
    const changed = vi.fn();
    render(<Fixture changed={changed} />);
    const select = screen.getByRole('combobox');
    select.focus();
    await user.keyboard('{ArrowDown}{End}{Escape}');
    expect(select).toHaveValue('alpha');
    expect(changed).not.toHaveBeenCalled();
    await user.click(select);
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(select).toHaveValue('alpha');
});

test('typeahead and Tab commit while preserving ordinary tab navigation', async () => {
    const user = userEvent.setup();
    render(<Fixture />);
    screen.getByRole('combobox').focus();
    await user.keyboard('g');
    await user.tab();
    expect(screen.getByRole('combobox')).toHaveValue('gamma');
    expect(screen.getByRole('button', { name: 'Next' })).toHaveFocus();
});

test('does not choose disabled values or emit duplicate changes for the current value', async () => {
    const user = userEvent.setup();
    const changed = vi.fn();
    render(<Fixture changed={changed} />);
    const select = screen.getByRole('combobox');
    await user.click(select);
    await user.click(within(screen.getByRole('listbox')).getByRole('option', { name: 'Blocked' }));
    expect(select).toHaveValue('alpha');
    await user.click(within(screen.getByRole('listbox')).getByRole('option', { name: 'Alpha' }));
    expect(changed).not.toHaveBeenCalled();
});

test('preserves the native change contract used by existing form integrations', () => {
    render(<Fixture />);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'beta' } });
    expect(screen.getByRole('combobox')).toHaveDisplayValue('Beta');
});

test('inherits disabled fieldsets and disposes the portal when unmounted', async () => {
    const user = userEvent.setup();
    const { unmount } = render(<Fixture />);
    await user.click(screen.getByRole('combobox'));
    unmount();
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    render(
        <fieldset disabled>
            <label htmlFor="disabled">Disabled select</label>
            <Select id="disabled">
                <option>Alpha</option>
            </Select>
        </fieldset>,
    );
    await user.click(screen.getByRole('combobox', { name: 'Disabled select' }));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
});

test('uses the document nonce for positioning CSS and closes when the anchor moves', async () => {
    const nonce = document.createElement('style');
    nonce.nonce = 'synthetic-csp-nonce';
    document.head.append(nonce);
    try {
        const user = userEvent.setup();
        render(<Fixture />);
        await user.click(screen.getByRole('combobox'));
        const dynamic = Array.from(document.querySelectorAll('style')).find((style) =>
            style.textContent?.includes('select-picker-'),
        );
        expect(dynamic?.nonce).toBe('synthetic-csp-nonce');
        fireEvent(window, new Event('resize'));
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    } finally {
        nonce.remove();
    }
});

test('opens through click-only activation without relying on native OS picker placement', () => {
    render(<Fixture />);
    fireEvent.click(screen.getByRole('combobox'));
    expect(screen.getByRole('listbox', { name: 'Service' })).toBeInTheDocument();
});
