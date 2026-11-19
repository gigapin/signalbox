/** Verify modal creation, local suggestions, retained card drafts and ten-item channel pagination. */
import { router } from '@inertiajs/react';
import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import type { ChannelView } from '../../resources/js/components/notifications/types';
import Channels from '../../resources/js/pages/Notifications/Channels';
import { installDialogSupport } from './dialogSupport';
import { renderInertia } from './renderInertia';

/** Restore methods that jsdom omits after every mounted fixture has been disposed. */
let restoreDialogs: () => void = () => undefined;
/** Preserve the browser prototype independently of the per-file test double. */
const originalScroll = Object.getOwnPropertyDescriptor(Element.prototype, 'scrollIntoView');

beforeEach(() => {
    restoreDialogs = installDialogSupport();
    Object.defineProperty(Element.prototype, 'scrollIntoView', {
        configurable: true,
        value: vi.fn(),
    });
});

afterEach(() => {
    cleanup();
    restoreDialogs();
    if (originalScroll) Object.defineProperty(Element.prototype, 'scrollIntoView', originalScroll);
    else Reflect.deleteProperty(Element.prototype, 'scrollIntoView');
});

/**
 * @param id Stable synthetic identity.
 * @returns A complete safe channel with non-secret editable values only.
 */
function channel(id: number): ChannelView {
    return {
        id,
        type: 'email',
        name: `Channel ${String(id).padStart(2, '0')}`,
        hint: 'o•••@example.test',
        isActive: false,
        isStub: false,
        verifiedAt: null,
        verificationPending: false,
        verificationExpiresAt: null,
        editable: { address: 'ops@example.test' },
        usedBy: [],
        quietHours: null,
    };
}

/**
 * @param count Local bounded collection size.
 * @param manage Whether creation and editing are permitted.
 * @returns The production page envelope with synthetic data.
 */
function props(count: number, manage = false) {
    return {
        team: { id: 7, name: 'Synthetic team' },
        channels: Array.from({ length: count }, (_, index) => channel(index + 1)),
        policies: [],
        unnotifiedMonitors: 0,
        channelTypes: [{ value: 'email', label: 'Email', isStub: false, secretFields: [] }],
        limits: {
            channelsPerTeam: 25,
            policiesPerTeam: 10,
            stepsPerPolicy: 5,
            channelsPerStep: 10,
            maxStepDelayMinutes: 1440,
            nameMaxLength: 100,
            codeLength: 6,
        },
        can: { manage },
    };
}

test.each([0, 1, 10])(
    'omits pagination for %i channels and puts search in the heading without a card',
    (count) => {
        renderInertia('Signalbox', Channels, props(count));
        expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
        const header = screen
            .getByRole('heading', { level: 1, name: 'Channels' })
            .closest('header');
        const search = within(header!).getByRole('combobox', { name: 'Find channels' });
        expect(search.closest('.ui-panel')).toBeNull();
        expect(screen.queryByText(/First add a channel/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add channel' })).not.toBeInTheDocument();
    },
);

test('paginates eleven channels without unmounting card editors or their unsent drafts', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Channels, props(11, true));
    const list = screen.getByRole('list', { name: 'Notification channels' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(10);
    const first = within(list).getByRole('listitem', { name: 'Channel 01 · Email' });
    expect(first).toHaveClass('ui-panel', 'ui-channel-card');
    await user.click(within(first).getByRole('button', { name: 'Edit channel' }));
    const name = within(first).getByLabelText('Name');
    await user.type(name, ' draft');
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(first).toBeInTheDocument();
    expect(first).toHaveAttribute('hidden');
    expect(name).toHaveValue('Channel 01 draft');
    await user.click(screen.getByRole('button', { name: 'Previous' }));
    expect(first).not.toHaveAttribute('hidden');
    expect(within(first).getByLabelText('Name')).toBe(name);
});

test('opens clickable suggestions on focus and reaches a channel beyond the first page', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Channels, props(11));
    const search = screen.getByRole('combobox', { name: 'Find channels' });
    act(() => search.focus());
    const suggestions = screen.getByRole('listbox', { name: 'Find channels suggestions' });
    expect(within(suggestions).getAllByRole('option')).toHaveLength(11);
    await user.type(search, '11');
    await user.click(screen.getByRole('option', { name: 'Channel 11' }));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    const card = screen.getByRole('listitem', { name: 'Channel 11 · Email' });
    await waitFor(() => expect(card).toHaveFocus());
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
    expect(search).toHaveValue('Channel 11');
});

test('supports suggestion selection from the keyboard and explains empty matches', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Channels, props(2));
    const search = screen.getByRole('combobox', { name: 'Find channels' });
    await user.click(search);
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}');
    await waitFor(() =>
        expect(screen.getByRole('listitem', { name: 'Channel 02 · Email' })).toHaveFocus(),
    );
    await user.clear(search);
    await user.type(search, 'missing');
    expect(screen.getByText('No channels match this search.')).toBeInTheDocument();
    expect(screen.getByText('No matches.')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
});

test('preserves a creation draft on close, blocks pending dismissal and resets only after acceptance', async () => {
    const user = userEvent.setup();
    let visit: NonNullable<Parameters<typeof router.visit>[1]> | undefined;
    vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
        visit = options;
    });
    renderInertia('Signalbox', Channels, props(1, true));
    const trigger = screen.getByRole('button', { name: 'Add channel' });
    expect(trigger.closest('header')).toBe(
        screen.getByRole('heading', { level: 1 }).closest('header'),
    );
    await user.click(trigger);
    let modal = screen.getByRole('dialog', { name: 'Add a channel' });
    const name = within(modal).getByLabelText('Name');
    await user.type(name, 'New channel');
    await user.click(within(modal).getByRole('button', { name: 'Close' }));
    expect(trigger).toHaveFocus();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await user.click(trigger);
    modal = screen.getByRole('dialog', { name: 'Add a channel' });
    expect(within(modal).getByLabelText('Name')).toHaveValue('New channel');
    await user.click(within(modal).getByRole('button', { name: 'Add channel' }));
    expect(within(modal).getByRole('button', { name: 'Close' })).toBeDisabled();
    fireEvent(modal, new Event('cancel', { bubbles: true, cancelable: true }));
    expect(modal).toHaveAttribute('open');
    act(() => {
        visit?.onError?.({ name: 'Name refused' });
        visit?.onFinish?.({} as never);
    });
    expect(within(modal).getByText('Name refused')).toBeInTheDocument();
    expect(name).toHaveValue('New channel');
    await user.click(within(modal).getByRole('button', { name: 'Add channel' }));
    act(() => {
        visit?.onSuccess?.({} as never);
        visit?.onFinish?.({} as never);
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await user.click(trigger);
    expect(
        within(screen.getByRole('dialog', { name: 'Add a channel' })).getByLabelText('Name'),
    ).toHaveValue('');
});

test('does not open creation when the team has reached its channel quota', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Channels, props(25, true));
    const trigger = screen.getByRole('button', { name: 'Add channel' });
    expect(trigger).toBeDisabled();
    await user.click(trigger);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByText('The team has reached its limit of 25 channels.')).toBeInTheDocument();
});

test('Escape closes the idle modal and restores focus and background scroll styles', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Channels, props(1, true));
    const overflow = document.documentElement.style.overflow;
    const gutter = document.documentElement.style.scrollbarGutter;
    const trigger = screen.getByRole('button', { name: 'Add channel' });
    await user.click(trigger);
    const modal = screen.getByRole('dialog', { name: 'Add a channel' });
    expect(document.documentElement.style.overflow).toBe('hidden');
    fireEvent(modal, new Event('cancel', { bubbles: true, cancelable: true }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(document.documentElement.style.overflow).toBe(overflow);
    expect(document.documentElement.style.scrollbarGutter).toBe(gutter);
    expect(trigger).toHaveFocus();
});
