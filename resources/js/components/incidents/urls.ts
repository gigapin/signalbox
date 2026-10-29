/** Build incident page, mutation and maintenance URLs in one place; the application uses no route helper. */
import { monitorPath } from '../monitors/urls';
import type { IncidentFilters } from './types';

/**
 * @param teamId Route team.
 * @returns Base path of the team's incident list.
 */
export function incidentsPath(teamId: number): string {
    return `/teams/${teamId}/incidents`;
}

/**
 * @param teamId Route team.
 * @param incidentId Team-local incident.
 * @returns Path of one incident's page; mutation endpoints are below it.
 */
export function incidentPath(teamId: number, incidentId: number): string {
    return `${incidentsPath(teamId)}/${incidentId}`;
}

/**
 * @param teamId Route team.
 * @param monitorId Team-local monitor.
 * @returns Collection path of the monitor's maintenance windows.
 */
export function maintenancePath(teamId: number, monitorId: number): string {
    return `${monitorPath(teamId, monitorId)}/maintenance`;
}

/**
 * Keep only filters that narrow the list, so URLs stay short and shareable.
 * @param filters Current filter values; null values are omitted.
 * @param page One-based page; the first page is implicit.
 * @returns Query parameters in a stable key order.
 */
export function incidentListQuery(filters: IncidentFilters, page = 1): Record<string, string> {
    const query: Record<string, string> = {};

    for (const key of ['state', 'severity'] as const) {
        const value = filters[key];

        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    if (page > 1) {
        query.page = String(page);
    }

    return query;
}

/**
 * @param teamId Route team.
 * @param filters Active filters.
 * @param page One-based page.
 * @returns Relative list URL carrying the filters and page.
 */
export function incidentListUrl(teamId: number, filters: IncidentFilters, page = 1): string {
    const search = new URLSearchParams(incidentListQuery(filters, page)).toString();

    return search === '' ? incidentsPath(teamId) : `${incidentsPath(teamId)}?${search}`;
}
