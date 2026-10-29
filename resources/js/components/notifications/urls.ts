/** Build notification page and mutation URLs in one place; the application uses no route helper. */
import { monitorPath } from '../monitors/urls';

/**
 * @param teamId Route team.
 * @returns Path of the team's notification settings page.
 */
export function notificationsPath(teamId: number): string {
    return `/teams/${teamId}/notifications`;
}

/**
 * @param teamId Route team.
 * @returns Collection path of the team's channels.
 */
export function channelsPath(teamId: number): string {
    return `/teams/${teamId}/notification-channels`;
}

/**
 * @param teamId Route team.
 * @param channelId Team-local channel.
 * @returns Path of one channel; verification, activation and signing-secret endpoints are below it.
 */
export function channelPath(teamId: number, channelId: number): string {
    return `${channelsPath(teamId)}/${channelId}`;
}

/**
 * @param teamId Route team.
 * @returns Collection path of the team's escalation policies.
 */
export function policiesPath(teamId: number): string {
    return `/teams/${teamId}/escalation-policies`;
}

/**
 * @param teamId Route team.
 * @param policyId Team-local policy.
 * @returns Path of one policy.
 */
export function policyPath(teamId: number, policyId: number): string {
    return `${policiesPath(teamId)}/${policyId}`;
}

/**
 * @param teamId Route team.
 * @param monitorId Team-local monitor.
 * @returns Endpoint choosing the monitor's escalation policy.
 */
export function monitorEscalationPath(teamId: number, monitorId: number): string {
    return `${monitorPath(teamId, monitorId)}/escalation-policy`;
}
