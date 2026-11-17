/** Exercise shared form semantics across native editing, selection, validation and choice controls. */
import { createRef } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import ChoiceField from '../../resources/js/components/ui/ChoiceField';
import SelectField from '../../resources/js/components/ui/SelectField';
import TextareaField from '../../resources/js/components/ui/TextareaField';
import FormField from '../../resources/js/components/ui/FormField';

test('preserves multiline drafts and ref while updating accessible validation', async () => {
    const user = userEvent.setup();
    const ref = createRef<HTMLTextAreaElement>();
    const view = render(
        <TextareaField ref={ref} label="Reason" hint="Visible to your team." defaultValue="" />,
    );
    const field = screen.getByRole('textbox', { name: 'Reason' });
    await user.type(field, 'Deploy\nComplete');
    view.rerender(
        <TextareaField
            ref={ref}
            label="Reason"
            hint="Visible to your team."
            error="Add more detail."
            defaultValue=""
        />,
    );
    expect(ref.current).toBe(field);
    expect(field).toHaveValue('Deploy\nComplete');
    expect(field).toHaveAccessibleDescription('Visible to your team. Add more detail.');
    expect(field).toHaveAttribute('aria-invalid', 'true');
    view.rerender(
        <TextareaField ref={ref} label="Reason" hint="Visible to your team." defaultValue="" />,
    );
    expect(field).toHaveAccessibleDescription('Visible to your team.');
    expect(field).not.toHaveAttribute('aria-invalid');
});

test('selects through the shared picker with native changes and form submission values', async () => {
    const user = userEvent.setup();
    const changed = vi.fn();
    const ref = createRef<HTMLSelectElement>();
    const view = render(
        <form aria-label="Settings">
            <SelectField
                ref={ref}
                name="region"
                label="Region"
                hint="Check location."
                defaultValue="eu"
                onChange={changed}
            >
                <option value="eu">Europe</option>
                <option value="us">United States</option>
            </SelectField>
        </form>,
    );
    const select = screen.getByRole('combobox', { name: 'Region' });
    expect(ref.current).toBe(select);
    expect(select).toHaveAccessibleDescription('Check location.');
    await user.click(select);
    await user.click(
        within(screen.getByRole('listbox')).getByRole('option', { name: 'United States' }),
    );
    expect(changed).toHaveBeenCalledTimes(1);
    expect(select).toHaveValue('us');
    expect(new FormData(screen.getByRole<HTMLFormElement>('form')).get('region')).toBe('us');
    view.rerender(
        <form aria-label="Settings">
            <SelectField
                ref={ref}
                name="region"
                label="Region"
                error="Region unavailable."
                defaultValue="eu"
            >
                <option value="eu">Europe</option>
                <option value="us">United States</option>
            </SelectField>
        </form>,
    );
    expect(select).toHaveAccessibleDescription('Region unavailable.');
    expect(select).toHaveValue('us');
});

test('keeps native label activation, keyboard choice and disabled fieldset semantics', async () => {
    const user = userEvent.setup();
    const ref = createRef<HTMLInputElement>();
    render(
        <>
            <ChoiceField ref={ref} label="Send updates" hint="Email only." />
            <ChoiceField type="radio" name="mode" label="Summary" value="summary" defaultChecked />
            <ChoiceField type="radio" name="mode" label="Every event" value="all" />
            <fieldset disabled>
                <ChoiceField label="Locked choice" />
                <SelectField label="Locked region">
                    <option>Europe</option>
                </SelectField>
            </fieldset>
        </>,
    );
    const choice = screen.getByRole('checkbox', { name: 'Send updates' });
    await user.click(screen.getByText('Send updates'));
    expect(ref.current).toBe(choice);
    expect(choice).toBeChecked();
    expect(choice).toHaveAccessibleDescription('Email only.');
    await user.keyboard(' ');
    expect(choice).not.toBeChecked();
    await user.click(screen.getByRole('radio', { name: 'Every event' }));
    expect(screen.getByRole('radio', { name: 'Summary' })).not.toBeChecked();
    expect(screen.getByRole('radio', { name: 'Every event' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Locked choice' })).toBeDisabled();
    expect(screen.getByRole('combobox', { name: 'Locked region' })).toBeDisabled();
});

test('supports composed controls and hidden labels without unsafe error markup', () => {
    render(
        <>
            <FormField label="Address" hint="Public slug." error={'<script>alert(1)</script>'}>
                {(binding) => (
                    <div>
                        <span>/status/</span>
                        <input {...binding} />
                    </div>
                )}
            </FormField>
            <TextareaField labelHidden label="Compact notes" />
        </>,
    );
    const address = screen.getByRole('textbox', { name: 'Address' });
    expect(address).toHaveAccessibleDescription('Public slug. <script>alert(1)</script>');
    expect(screen.getByRole('alert').querySelector('script')).toBeNull();
    expect(screen.getByRole('textbox', { name: 'Compact notes' })).toBeInTheDocument();
});
