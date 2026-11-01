/** Let a team member choose a recent month and download its PDF report. */
import { useState } from 'react';

/** Months offered: the current one and the eleven before it. */
const MONTHS_OFFERED = 12;

/** One selectable report month. */
export interface ReportMonthOption {
    /** Month as YYYY-MM, as the report route expects it. */
    value: string;
    /** Month name and year for people. */
    label: string;
}

/**
 * List recent months newest first, starting from the current month in the team's timezone, which is the one the server
 * applies, whatever the browser's timezone is.
 */
export function reportMonths(timezone: string, now: Date = new Date()): ReportMonthOption[] {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(now);
    const year = Number(parts.find((part) => part.type === 'year')?.value);
    const month = Number(parts.find((part) => part.type === 'month')?.value);

    return Array.from({ length: MONTHS_OFFERED }, (_, offset) => {
        const date = new Date(Date.UTC(year, month - 1 - offset, 1));

        return {
            value: `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`,
            label: date.toLocaleString('en', { month: 'long', year: 'numeric', timeZone: 'UTC' }),
        };
    });
}

/** Render the month choice with a plain link, because Inertia navigation cannot deliver a file. The last complete month comes preselected. */
export default function MonthlyReportDownload({
    teamId,
    timezone,
}: {
    teamId: number;
    timezone: string;
}) {
    const [options] = useState(() => reportMonths(timezone));
    const [selected, setSelected] = useState(options[1]?.value ?? '');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <label htmlFor="report-month" className="text-slate-400">
                Monthly report
            </label>
            <select
                id="report-month"
                value={selected}
                onChange={(event) => setSelected(event.target.value)}
                className="rounded border border-slate-700 bg-slate-900 px-2 py-1 text-slate-100"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <a href={`/teams/${teamId}/reports/${selected}`}>Download PDF</a>
        </div>
    );
}
