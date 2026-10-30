/** Cross-check the exact PHP-produced fixture against frontend validation and numeric execution identity handling. */
import { expect, test } from 'vitest';
import { validTeamEvent, type TeamEventName } from '../../resources/js/realtime/types';
import contract from '../Fixtures/team-broadcast-payloads.json';

test.each(Object.entries(contract))('accepts the production %s wire contract', (name, payload) => {
    expect(validTeamEvent(name as TeamEventName, payload)).toBe(true);
});

test.each([
    0,
    -1,
    1.5,
    Number.MAX_SAFE_INTEGER + 1,
    '21',
    '00000000-0000-4000-8000-000000000001',
    null,
])('refuses malformed execution identity %s', (executionId) => {
    expect(
        validTeamEvent('CheckCompleted', { ...contract.CheckCompleted, execution_id: executionId }),
    ).toBe(false);
});
