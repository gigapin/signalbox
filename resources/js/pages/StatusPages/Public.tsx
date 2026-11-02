/** Show a published status page to anonymous visitors, in the appearance its team chose. */
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import StatusPageHead from '../../components/status-pages/StatusPageHead';
import SubscribeForm from '../../components/status-pages/SubscribeForm';
import type {
    PublicComponent,
    PublicHistoryDay,
    PublicStatusPage,
    StatusPageMetadata,
} from '../../components/status-pages/types';

/** Page props: the explicit visitor projection only. */
interface PublicProps {
    /** Visitor projection. */
    page: PublicStatusPage;
    /** Live crawler policy, always included by the server even on partial visits. */
    metadata?: StatusPageMetadata;
    /** Live relative endpoint, outside the visitor content cache. */
    subscribePath?: string;
    /** Generic request acknowledgement from the session. */
    status?: string;
    /** Inertia page props are an open record. */
    [key: string]: unknown;
}

/** Complete class lists per color scheme, so the build keeps them and no server value reaches a class name. */
const MODES: Record<string, string> = {
    system: 'bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100',
    light: 'bg-white text-slate-900',
    dark: 'bg-slate-950 text-slate-100',
};

/** Decorative bar color per accent; text never uses it, so contrast does not depend on the choice. */
const ACCENTS: Record<string, string> = {
    emerald: 'bg-emerald-500',
    blue: 'bg-blue-500',
    violet: 'bg-violet-500',
    amber: 'bg-amber-500',
    slate: 'bg-slate-500',
};

/** Text for each current state; meaning is never carried by color alone. */
const HEALTH: Record<string, string> = {
    operational: 'Operational',
    unknown: 'Status unknown',
    maintenance: 'Under maintenance',
    partial_outage: 'Partial outage',
    outage: 'Outage',
};

/** Sentence for the page as a whole. */
const OVERALL: Record<string, string> = {
    operational: 'All systems operational',
    unknown: 'Current status not fully known',
    maintenance: 'Maintenance in progress',
    partial_outage: 'Some systems are having problems',
    outage: 'Major outage',
};

/** Bar color per day state. */
const DAY_COLORS: Record<string, string> = {
    up: 'bg-emerald-500',
    down: 'bg-red-600',
    none: 'bg-slate-400',
};

/** Text for each incident status, as written on the private timeline. */
const INCIDENT_STATUS: Record<string, string> = {
    investigating: 'Investigating',
    identified: 'Identified',
    monitoring: 'Monitoring',
    resolved: 'Resolved',
};

/**
 * @param instant UTC instant from the server.
 * @returns Date and time in UTC, the same for every visitor.
 */
function utcLabel(instant: string): string {
    return `${instant.slice(0, 10)} ${instant.slice(11, 16)} UTC`;
}

/**
 * @param day One day of the history.
 * @returns Text of the day; a day without data never reads as available.
 */
function dayLabel(day: PublicHistoryDay): string {
    if (day.uptimePercent === null) {
        return `${day.date}: no data`;
    }

    return `${day.date}: ${day.uptimePercent}% uptime, ${day.coveragePercent}% of the day observed`;
}

/**
 * @param component Component with its history.
 * @returns Summary read out in place of the 90 bars.
 */
function historySummary(component: PublicComponent): string {
    const down = component.days.filter((day) => day.state === 'down').length;
    const none = component.days.filter((day) => day.state === 'none').length;

    return `Last ${component.days.length} days: ${down} with downtime, ${none} without data.`;
}

/** The current state comes from monitoring; incident details appear only where someone published them. */
export default function Public() {
    const { page, subscribePath, status, metadata } = usePage<PublicProps>().props;

    useEffect(() => {
        // The server summary is outside Inertia's root and stays readable if JavaScript cannot mount.
        document.getElementById('status-page-snapshot')?.remove();
    }, []);

    return (
        <>
            <StatusPageHead metadata={metadata} fallbackTitle="Status page" />
            <main className={`min-h-screen px-6 py-12 ${MODES[page.theme.mode] ?? MODES.system}`}>
                <div className="mx-auto max-w-3xl">
                    <div
                        data-testid="accent"
                        aria-hidden="true"
                        className={`mb-4 h-1 w-16 rounded ${ACCENTS[page.theme.accent] ?? ACCENTS.emerald}`}
                    />
                    <h1 className="text-3xl font-semibold">{page.title}</h1>
                    {page.description !== null && <p className="mt-3">{page.description}</p>}
                    <p role="status" className="mt-6 text-xl font-medium">
                        {OVERALL[page.status] ?? OVERALL.unknown}
                    </p>
                    {page.components.length === 0 ? (
                        <p className="mt-8">This page has no components yet.</p>
                    ) : (
                        <ul aria-label="Components" className="mt-8">
                            {page.components.map((component) => (
                                <li
                                    key={component.name}
                                    className="border-b border-slate-300 py-4 dark:border-slate-700"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h2 className="font-medium">{component.name}</h2>
                                        <span>{HEALTH[component.status] ?? HEALTH.unknown}</span>
                                    </div>
                                    <div
                                        role="img"
                                        aria-label={historySummary(component)}
                                        className="mt-2 flex h-6 gap-px"
                                    >
                                        {component.days.map((day) => (
                                            <span
                                                key={day.date}
                                                title={dayLabel(day)}
                                                data-state={day.state}
                                                className={`flex-1 rounded-sm ${DAY_COLORS[day.state] ?? DAY_COLORS.none}`}
                                            />
                                        ))}
                                    </div>
                                    <p className="mt-1 text-sm">
                                        {component.uptimePercent === null
                                            ? 'No uptime data in the last 90 days.'
                                            : `${component.uptimePercent}% observed uptime in the last 90 days, excluding time without data.`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {subscribePath && <SubscribeForm path={subscribePath} status={status} />}
                    <section aria-labelledby="incidents-heading" className="mt-10">
                        <h2 id="incidents-heading" className="text-xl font-semibold">
                            Published incidents
                        </h2>
                        {page.incidents.length === 0 ? (
                            <p className="mt-2">Nothing published in the last 90 days.</p>
                        ) : (
                            <ul aria-label="Published incidents" className="mt-4 grid gap-6">
                                {page.incidents.map((incident, index) => (
                                    <li key={index}>
                                        <h3 className="font-medium">
                                            {incident.component}:{' '}
                                            {INCIDENT_STATUS[incident.status] ?? incident.status}
                                        </h3>
                                        <ol className="mt-2 grid gap-2">
                                            {incident.updates.map((update, position) => (
                                                <li key={position}>
                                                    <p className="whitespace-pre-line">
                                                        <span className="font-medium">
                                                            {INCIDENT_STATUS[update.status] ??
                                                                update.status}
                                                            :
                                                        </span>{' '}
                                                        {update.message}
                                                    </p>
                                                    <time
                                                        dateTime={update.publishedAt}
                                                        className="text-sm"
                                                    >
                                                        {utcLabel(update.publishedAt)}
                                                    </time>
                                                </li>
                                            ))}
                                        </ol>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <p className="mt-10 text-sm">History days are whole UTC days.</p>
                </div>
            </main>
        </>
    );
}
