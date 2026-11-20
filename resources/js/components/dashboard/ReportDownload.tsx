/** Select complete local dates and download an authorized aggregate PDF. */
import { DayPicker, TZDate, type DateRange } from '@daypicker/react';
import '@daypicker/react/style.css';
import { createPortal } from 'react-dom';
import { useEffect, useRef, useState } from 'react';
import Button from '../ui/Button';

/** Server-owned inclusive calendar bounds and initial period. */
export interface ReportCalendar {
    /** Earliest complete retained date. */
    min: string;
    /** Yesterday in the team timezone. */
    max: string;
    /** Default start date. */
    from: string;
    /** Default final date. */
    to: string;
}

/** Convert a date-only server value without using the browser timezone. */
function calendarDate(value: string, timezone: string): TZDate {
    const year = Number(value.slice(0, 4));
    const month = Number(value.slice(5, 7));
    const day = Number(value.slice(8, 10));
    return new TZDate(year, month - 1, day, timezone);
}

/** Serialize calendar dates without shifting them through UTC. */
function dateValue(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Authorized calendar input and heading action destination. */
interface ReportDownloadProps {
    /** Route tenant identity. */
    teamId: number;
    /** Team IANA timezone. */
    timezone: string;
    /** Server-projected inclusive bounds and defaults. */
    calendar: ReportCalendar;
    /** Heading slot owned by the current authorized page. */
    actionsTarget: HTMLElement | null;
    /** Server validation errors returned to the report page. */
    errors?: Record<string, string>;
}

/** Keep committed dates separate from a cancellable two-click calendar draft. */
export default function ReportDownload({
    teamId,
    timezone,
    calendar,
    actionsTarget,
    errors = {},
}: ReportDownloadProps) {
    const [selected, setSelected] = useState({ from: calendar.from, to: calendar.to });
    const [draft, setDraft] = useState<DateRange>();
    const [open, setOpen] = useState(false);
    const [months, setMonths] = useState(1);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);

    /** Close the draft and restore keyboard focus to its trigger. */
    function close() {
        setOpen(false);
        trigger.current?.focus();
    }

    useEffect(() => {
        if (!window.matchMedia) return;
        const media = window.matchMedia('(min-width: 1024px)');
        const update = () => setMonths(media.matches ? 2 : 1);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);

    useEffect(() => {
        if (!open) return;
        const dismiss = (event: PointerEvent) => {
            if (event.target instanceof Node && !root.current?.contains(event.target))
                setOpen(false);
        };
        document.addEventListener('pointerdown', dismiss);
        return () => document.removeEventListener('pointerdown', dismiss);
    }, [open]);

    const download = (
        <a
            className="ui-button"
            aria-disabled={open || undefined}
            href={
                open
                    ? undefined
                    : `/teams/${teamId}/reports/download?from=${selected.from}&to=${selected.to}`
            }
        >
            Download PDF
        </a>
    );

    return (
        <>
            {actionsTarget && createPortal(download, actionsTarget)}
            <div
                ref={root}
                className="ui-report-field"
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && open) {
                        event.preventDefault();
                        close();
                    }
                }}
            >
                <span id="report-period-label" className="ui-label">
                    Report period
                </span>
                <button
                    ref={trigger}
                    type="button"
                    className="ui-input text-left"
                    aria-labelledby="report-period-label report-period-value"
                    aria-haspopup="dialog"
                    aria-expanded={open}
                    onClick={() => {
                        if (open) {
                            close();
                            return;
                        }
                        setDraft(undefined);
                        setOpen(true);
                    }}
                >
                    <span id="report-period-value">
                        {selected.from} — {selected.to}
                    </span>
                </button>
                {(errors.from || errors.to) && <p role="alert">{errors.from || errors.to}</p>}
                {open && (
                    <div
                        className="ui-report-calendar"
                        role="dialog"
                        aria-label="Select report period"
                    >
                        <DayPicker
                            timeZone={timezone}
                            mode="range"
                            selected={draft}
                            defaultMonth={calendarDate(selected.from, timezone)}
                            numberOfMonths={months}
                            startMonth={calendarDate(calendar.min, timezone)}
                            endMonth={calendarDate(calendar.max, timezone)}
                            disabled={[
                                { before: calendarDate(calendar.min, timezone) },
                                { after: calendarDate(calendar.max, timezone) },
                            ]}
                            autoFocus
                            onDayClick={(day, modifiers) => {
                                if (modifiers.disabled) return;
                                if (!draft?.from || draft.to) setDraft({ from: day });
                                else
                                    setDraft(
                                        day < draft.from
                                            ? { from: day, to: draft.from }
                                            : { from: draft.from, to: day },
                                    );
                            }}
                            footer={
                                <span aria-live="polite">
                                    {draft?.from
                                        ? `${dateValue(draft.from)}${draft.to ? ` — ${dateValue(draft.to)}` : ' — Select end date'}`
                                        : 'Select start date'}
                                </span>
                            }
                        />
                        <div className="ui-actions mt-4">
                            <Button
                                disabled={!draft?.from || !draft.to}
                                onClick={() => {
                                    if (draft?.from && draft.to)
                                        setSelected({
                                            from: dateValue(draft.from),
                                            to: dateValue(draft.to),
                                        });
                                    close();
                                }}
                            >
                                Apply
                            </Button>
                            <Button variant="secondary" onClick={close}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
