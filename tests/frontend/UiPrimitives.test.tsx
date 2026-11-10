/** Verify accessible primitive interactions and composition without a server or domain fixture. */
import { createRef } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Alert from '../../resources/js/components/ui/Alert';
import Badge from '../../resources/js/components/ui/Badge';
import Button from '../../resources/js/components/ui/Button';
import DataTable from '../../resources/js/components/ui/DataTable';
import InputField from '../../resources/js/components/ui/InputField';
import Panel, { PageHeading } from '../../resources/js/components/ui/Panel';
import { StateBadge, StatusBadge } from '../../resources/js/components/monitors/MonitorBadges';
import {
    IncidentStatusBadge,
    SeverityBadge,
} from '../../resources/js/components/incidents/IncidentBadges';

test('defaults actions inside forms to non-submitting buttons and supports keyboard activation', async () => {
    const user = userEvent.setup();
    const submit = vi.fn((event: React.FormEvent) => event.preventDefault());
    const click = vi.fn();
    render(
        <form onSubmit={submit}>
            <Button onClick={click}>Pause monitor</Button>
        </form>,
    );

    await user.tab();
    expect(screen.getByRole('button', { name: 'Pause monitor' })).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(click).toHaveBeenCalledTimes(1);
    expect(submit).not.toHaveBeenCalled();
});

test('submits explicitly and prevents another activation while awaiting caller confirmation', async () => {
    const user = userEvent.setup();
    const submit = vi.fn((event: React.FormEvent) => event.preventDefault());
    const view = render(
        <form onSubmit={submit}>
            <Button type="submit">Save monitor</Button>
        </form>,
    );
    await user.click(screen.getByRole('button', { name: 'Save monitor' }));
    expect(submit).toHaveBeenCalledTimes(1);

    view.rerender(
        <form onSubmit={submit}>
            <Button type="submit" busy busyLabel="Saving monitor…">
                Save monitor
            </Button>
        </form>,
    );
    const button = screen.getByRole('button', { name: 'Saving monitor…' });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
    await user.click(button);
    expect(submit).toHaveBeenCalledTimes(1);

    view.rerender(<Button disabled>Unavailable action</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
    expect(screen.getByRole('button')).not.toHaveAttribute('aria-busy');
});

test('exposes required input label, units, external help and live validation without losing the draft', async () => {
    const user = userEvent.setup();
    const inputRef = createRef<HTMLInputElement>();
    const view = render(
        <>
            <p id="external-help">Plan minimum applies.</p>
            <InputField
                ref={inputRef}
                id="interval"
                label="Interval"
                required
                hint="Seconds, from 60 to 86400."
                aria-describedby="external-help"
                defaultValue="60"
            />
        </>,
    );
    const input = screen.getByRole('textbox', { name: 'Interval (required)' });
    expect(input).toBeRequired();
    expect(input).toHaveAccessibleDescription('Plan minimum applies. Seconds, from 60 to 86400.');
    expect(inputRef.current).toBe(input);
    await user.clear(input);
    await user.type(input, '45');

    view.rerender(
        <>
            <p id="external-help">Plan minimum applies.</p>
            <InputField
                ref={inputRef}
                id="interval"
                label="Interval"
                required
                hint="Seconds, from 60 to 86400."
                aria-describedby="external-help"
                error="Use at least 60 seconds."
                defaultValue="60"
            />
        </>,
    );
    expect(input).toHaveValue('45');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription(
        'Plan minimum applies. Seconds, from 60 to 86400. Use at least 60 seconds.',
    );
    expect(screen.getByText('Use at least 60 seconds.')).toHaveAttribute('aria-live', 'polite');
});

test('generates independent field identities and removes stale validation references on correction', () => {
    const view = render(
        <>
            <InputField label="Name" error="Enter a name." />
            <InputField label="Name" />
        </>,
    );
    const fields = screen.getAllByRole('textbox', { name: 'Name' });
    expect(fields[0]?.id).not.toBe(fields[1]?.id);
    expect(fields[0]).toHaveAttribute('aria-invalid', 'true');
    expect(fields[1]).not.toHaveAttribute('aria-describedby');
    view.rerender(
        <>
            <InputField label="Name" />
            <InputField label="Name" />
        </>,
    );
    expect(fields[0]).not.toHaveAttribute('aria-invalid');
    expect(fields[0]).not.toHaveAttribute('aria-describedby');
    expect(screen.queryByText('Enter a name.')).not.toBeInTheDocument();
});

test('supports native label focus and caller-controlled input values', async () => {
    const user = userEvent.setup();
    const changed = vi.fn();
    const view = render(<InputField label="Monitor name" value="API" onChange={changed} />);
    await user.click(screen.getByText('Monitor name'));
    const input = screen.getByRole('textbox', { name: 'Monitor name' });
    expect(input).toHaveFocus();
    fireEvent.change(input, { target: { value: 'API production' } });
    expect(changed).toHaveBeenCalledTimes(1);
    view.rerender(<InputField label="Monitor name" value="API production" onChange={changed} />);
    expect(input).toHaveValue('API production');
});

test('creates named sections with independent heading references and native heading levels', () => {
    render(
        <>
            <PageHeading
                title="API"
                description="HTTP monitor"
                actions={<Button>Edit monitor</Button>}
            />
            <Panel title="Latency" description="Milliseconds, UTC">
                <Panel title="Latency" headingLevel={3}>
                    No observations yet.
                </Panel>
            </Panel>
        </>,
    );
    expect(screen.getByRole('heading', { level: 1, name: 'API' })).toBeVisible();
    expect(screen.getByRole('heading', { level: 2, name: 'Latency' })).toBeVisible();
    expect(screen.getByRole('heading', { level: 3, name: 'Latency' })).toBeVisible();
    const regions = screen.getAllByRole('region', { name: 'Latency' });
    expect(regions[0]?.getAttribute('aria-labelledby')).not.toBe(
        regions[1]?.getAttribute('aria-labelledby'),
    );
    expect(screen.getByRole('button', { name: 'Edit monitor' })).toBeVisible();
    expect(screen.getByText('No observations yet.')).toBeVisible();
});

test('keeps static notices silent and opts into appropriate live feedback explicitly', () => {
    const view = render(<Alert title="History unavailable">Choose a shorter window.</Alert>);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    view.rerender(
        <Alert title="Monitor saved" tone="success" announce="polite">
            Changes confirmed.
        </Alert>,
    );
    expect(screen.getByRole('status')).toHaveTextContent('Changes confirmed.');
    view.rerender(
        <Alert title="Request not confirmed" tone="warning" announce="assertive">
            Check latest data before trying again.
        </Alert>,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('Check latest data');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

test('retains domain labels and separates operational state from observation', () => {
    render(
        <>
            <StatusBadge status="up" />
            <StateBadge state="paused" />
            <IncidentStatusBadge status="resolved" />
            <SeverityBadge severity="critical" />
            <Badge>No observations</Badge>
        </>,
    );
    for (const label of ['Up', 'Paused', 'Resolved', 'Critical', 'No observations']) {
        expect(screen.getByText(label)).toBeVisible();
    }
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

test('preserves unknown domain values as text instead of assigning success', () => {
    render(
        <>
            <StatusBadge status="future-status" />
            <IncidentStatusBadge status="future-incident" />
        </>,
    );
    expect(screen.getByText('future-status')).toHaveAttribute('data-tone', 'neutral');
    expect(screen.getByText('future-incident')).toHaveAttribute('data-tone', 'neutral');
});

test('keeps table caption, column headers and links accessible inside the keyboard scroll region', async () => {
    const user = userEvent.setup();
    render(
        <DataTable caption="Team monitors">
            <thead>
                <tr>
                    <th scope="col">Monitor</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">
                        <a href="/teams/2/monitors/4">API</a>
                    </th>
                    <td>Unknown</td>
                </tr>
            </tbody>
        </DataTable>,
    );
    const region = screen.getByRole('region', { name: 'Team monitors' });
    expect(region).toHaveAccessibleDescription(
        'Scroll horizontally to view all columns when needed.',
    );
    expect(within(region).getByRole('table', { name: 'Team monitors' })).toBeVisible();
    expect(within(region).getAllByRole('columnheader')).toHaveLength(2);
    await user.tab();
    expect(region).toHaveFocus();
    await user.tab();
    expect(screen.getByRole('link', { name: 'API' })).toHaveFocus();
});
