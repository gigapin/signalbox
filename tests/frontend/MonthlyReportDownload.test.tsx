/** Verify report month options and the download link, including the team timezone at a month boundary. */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test } from 'vitest';
import MonthlyReportDownload, {
    reportMonths,
} from '../../resources/js/components/dashboard/MonthlyReportDownload';

test('lists twelve months newest first across a year change', () => {
    const months = reportMonths('UTC', new Date('2026-02-15T12:00:00Z'));

    expect(months).toHaveLength(12);
    expect(months[0]).toEqual({ value: '2026-02', label: 'February 2026' });
    expect(months[2]).toEqual({ value: '2025-12', label: 'December 2025' });
    expect(months[11]?.value).toBe('2025-03');
});

test('takes the current month from the team timezone, not from UTC', () => {
    const instant = new Date('2026-03-31T22:30:00Z');

    expect(reportMonths('Europe/Rome', instant)[0]?.value).toBe('2026-04');
    expect(reportMonths('UTC', instant)[0]?.value).toBe('2026-03');
    expect(reportMonths('America/New_York', new Date('2026-04-01T02:00:00Z'))[0]?.value).toBe(
        '2026-03',
    );
});

test('preselects the last complete month and follows the choice in a plain link', async () => {
    const months = reportMonths('Europe/Rome');

    render(<MonthlyReportDownload teamId={7} timezone="Europe/Rome" />);

    const link = screen.getByRole('link', { name: 'Download PDF' });

    expect(screen.getByLabelText('Monthly report')).toHaveValue(months[1]?.value);
    expect(link).toHaveAttribute('href', `/teams/7/reports/${String(months[1]?.value)}`);

    await userEvent.selectOptions(
        screen.getByLabelText('Monthly report'),
        String(months[3]?.value),
    );

    expect(link).toHaveAttribute('href', `/teams/7/reports/${String(months[3]?.value)}`);
});
