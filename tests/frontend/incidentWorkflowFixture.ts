/** Synthetic incident workflow data shared by interaction and recovery regressions. */
import type { IncidentDetail } from '../../resources/js/components/incidents/types';

/** Complete resolved/open-capable incident with private/public timeline entries and no credentials. */
export const workflowIncident: IncidentDetail = {
    id: 42,
    monitor: { id: 11, name: 'Synthetic API' },
    status: 'investigating',
    severity: 'major',
    cause: 'timeout',
    startedAt: '2026-11-11T10:00:00Z',
    confirmedAt: '2026-11-11T10:03:00Z',
    resolvedAt: null,
    durationSeconds: null,
    acknowledged: false,
    reopenedCount: 0,
    acknowledgedAt: null,
    responsible: null,
    rootCauseNote: null,
    lastReopenedAt: null,
    timeline: [
        {
            id: 100,
            status: 'investigating',
            message: 'Private diagnosis',
            author: 'Synthetic actor',
            isPublic: false,
            publishedAt: null,
            createdAt: '2026-11-11T10:04:00Z',
        },
    ],
};
/** Current authorized detail response; no payment, provider or customer data is simulated. */
export const workflowProps = {
    team: { id: 7, name: 'Synthetic team' },
    auth: { user: { name: 'Actor', email: 'actor@example.test' } },
    realtime: null,
    incident: workflowIncident,
    options: { states: ['open', 'resolved'], severities: ['minor', 'major', 'critical'] },
    postableStatuses: ['investigating', 'identified', 'monitoring', 'resolved'],
    textMaxLength: 5000,
    deliveries: [],
    can: { update: true },
    incidentListUrl: '/teams/7/incidents?state=open&severity=major&page=2',
    monitorUrl: '/teams/7/monitors/11?returnTo=%2Fteams%2F7%2Fmonitors%3Fq%3Dapi%26page%3D3',
};
