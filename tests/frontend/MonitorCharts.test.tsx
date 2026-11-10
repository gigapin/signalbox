/** Verify chart semantics, bounded controls, GET lifecycle and preservation of domain drafts and return context. */
import { router } from '@inertiajs/react';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test, vi } from 'vitest';
import Show from '../../resources/js/pages/Monitors/Show';
import { chartMetrics, chartsProps } from './monitorChartsFixture';
import { renderInertia } from './renderInertia';

/** Lifecycle callbacks retained by intercepted GET visits. */
type ReadOptions = NonNullable<Parameters<typeof router.get>[2]>;

test('shows regional means and percentile controls with measured zeros gaps and sample counts', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Show, chartsProps);
    const eu = within(screen.getByRole('region', { name: 'Response time EU' }));
    expect(eu.getByRole('img').querySelectorAll('circle')).toHaveLength(3);
    expect(eu.getByRole('img').querySelector('path')?.getAttribute('d')?.match(/M/g)).toHaveLength(
        3,
    );
    await user.click(eu.getByRole('button', { name: 'p95' }));
    expect(eu.getByRole('button', { name: 'p95' })).toHaveAttribute('aria-pressed', 'true');
    await user.click(eu.getByText('View response time eu data'));
    const table = within(eu.getByRole('table', { name: 'Response time EU, p95, UTC' }));
    expect(table.getByRole('columnheader', { name: 'Bucket end (UTC)' })).toBeInTheDocument();
    expect(table.getByRole('columnheader', { name: 'Measured samples' })).toBeInTheDocument();
    expect(table.getByText('500')).toBeInTheDocument();
    expect(table.getAllByText('No data').length).toBeGreaterThan(0);
    const whole = within(screen.getByRole('table', { name: 'Whole-window latency statistics' }));
    expect(whole.getByText('140')).toBeInTheDocument();
    expect(whole.getByText('5')).toBeInTheDocument();
});

test('offers agreed coverage and SLA separately without applying the latency region filter to availability', async () => {
    const user = userEvent.setup();
    renderInertia('Signalbox', Show, { ...chartsProps, metrics: chartMetrics('7d', 'us') });
    expect(screen.queryByRole('region', { name: 'Response time EU' })).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Response time US' })).toBeInTheDocument();
    const availability = within(screen.getByRole('region', { name: 'Availability over time' }));
    await user.click(availability.getByRole('button', { name: 'Data coverage' }));
    expect(availability.getByRole('img')).toHaveAccessibleName(
        'Availability over time: Data coverage',
    );
    await user.click(availability.getByRole('button', { name: 'SLA' }));
    expect(availability.queryByRole('img')).not.toBeInTheDocument();
    expect(
        availability.getByText('No observed availability for this series and window.'),
    ).toBeInTheDocument();
});

test('builds GET period and region controls from allowed fields and preserves the filtered return', async () => {
    const user = userEvent.setup();
    let options: ReadOptions | undefined;
    const get = vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) => {
        options = callbacks;
        callbacks?.onStart?.({} as never);
    });
    renderInertia('Signalbox', Show, chartsProps);
    const link = within(screen.getByRole('navigation', { name: 'Chart period' })).getByRole(
        'link',
        { name: '7 days' },
    );
    const href = link.getAttribute('href')!;
    expect(new URL(href, 'https://signalbox.test').searchParams.get('returnTo')).toBe(
        chartsProps.monitorListUrl,
    );
    await user.click(link);
    expect(get).toHaveBeenCalledWith(
        href,
        {},
        expect.objectContaining({ replace: false, preserveState: false, preserveScroll: true }),
    );
    expect(
        screen.getByText('Loading chart selection… Previous charts are still shown.'),
    ).toBeInTheDocument();
    act(() => {
        options?.onError?.({ selection: 'Refused' });
        options?.onFinish?.({} as never);
    });
    expect(screen.getByRole('alert')).toHaveTextContent('Chart selection could not be loaded');
    expect(screen.getByRole('region', { name: 'Response time EU' })).toBeInTheDocument();
    expect(get).toHaveBeenCalledOnce();
});

test('does not offer an unreadable period and never invents a line when history is unavailable', () => {
    const metrics = chartMetrics('30d');
    metrics.supportedPeriods = ['24h', '7d'];
    metrics.latency.available = false;
    metrics.uptime = { available: false, series: [] };
    renderInertia('Signalbox', Show, {
        ...chartsProps,
        metrics,
        rejectedMetricFilters: ['region'],
    });
    expect(
        within(screen.getByRole('navigation', { name: 'Chart period' })).queryByRole('link', {
            name: '30 days',
        }),
    ).not.toBeInTheDocument();
    expect(screen.getByText('30 days unavailable for this history')).toBeInTheDocument();
    expect(
        screen.getByText('Response time history is unavailable for this window.'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Availability chart history is unavailable/)).toBeInTheDocument();
    expect(screen.getByText('Invalid chart filters ignored')).toBeInTheDocument();
});

test.each([401, 403, 404])(
    'removes old charts after authoritative chart-navigation denial %s',
    async (status) => {
        const user = userEvent.setup();
        vi.spyOn(router, 'get').mockImplementation((_path, _data, callbacks) =>
            callbacks?.onStart?.({} as never),
        );
        renderInertia('Signalbox', Show, chartsProps);
        await user.click(
            within(screen.getByRole('navigation', { name: 'Latency region' })).getByRole('link', {
                name: 'US',
            }),
        );
        act(() => {
            document.dispatchEvent(
                new CustomEvent('inertia:invalid', {
                    cancelable: true,
                    detail: { response: { status } },
                }),
            );
        });
        expect(screen.queryByRole('region', { name: 'Monitor charts' })).not.toBeInTheDocument();
        expect(screen.getByText(/Live access is no longer available/)).toBeInTheDocument();
    },
);

test('keeps a dirty maintenance draft on Stay and visits exactly once after explicit discard', async () => {
    const showModal = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
    const close = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
        configurable: true,
        value: function (this: HTMLDialogElement) {
            this.open = true;
        },
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {
        configurable: true,
        value: function (this: HTMLDialogElement) {
            this.open = false;
        },
    });
    const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    const user = userEvent.setup();
    const view = renderInertia('Signalbox', Show, {
        ...chartsProps,
        can: { update: true, delete: false, pause: false, resume: false },
    });
    try {
        await user.type(screen.getByLabelText('Reason'), 'Unsent work');
        const seven = within(screen.getByRole('navigation', { name: 'Chart period' })).getByRole(
            'link',
            { name: '7 days' },
        );
        await user.click(seven);
        expect(get).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Stay' }));
        expect(screen.getByLabelText('Reason')).toHaveValue('Unsent work');
        await user.click(seven);
        await user.click(screen.getByRole('button', { name: 'Discard and leave' }));
        expect(get).toHaveBeenCalledOnce();
    } finally {
        view.unmount();
        if (showModal) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', showModal);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal');
        if (close) Object.defineProperty(HTMLDialogElement.prototype, 'close', close);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'close');
    }
});
