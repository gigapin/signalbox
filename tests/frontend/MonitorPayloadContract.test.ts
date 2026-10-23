/**
 * Pin what the monitor form submits for every type to the shared contract fixture. The PHP lifecycle suite
 * posts the same payloads to the production routes, so a drift on either side fails one of the two suites.
 */
import { expect, test } from 'vitest';
import {
    buildPayload,
    initialFormData,
    type MonitorFormData,
} from '../../resources/js/components/monitors/formData';
import type { MonitorDefaults } from '../../resources/js/components/monitors/types';
import contract from '../Fixtures/monitor-form-payloads.json';

/** One contract case: typed form changes and the exact payloads they must produce. */
interface ContractCase {
    /** Changes applied to the blank creation state. */
    form: Partial<MonitorFormData>;
    /** Expected creation payload. */
    create: Record<string, unknown>;
    /** Expected same-type edit payload with no secret replaced, for HTTP-family types only. */
    edit?: Record<string, unknown>;
}

/** Server form defaults; the PHP suite asserts they equal MonitorPresenter::defaults(). */
const defaults: MonitorDefaults = contract.defaults;

/** Contract cases keyed by monitor type. */
const cases: Record<string, ContractCase> = contract.cases;

/**
 * @param changes Typed edits a user makes in the form.
 * @returns Complete form state starting from the server defaults.
 */
function filled(changes: Partial<MonitorFormData>): MonitorFormData {
    return { ...initialFormData(defaults), ...changes };
}

/** Same-type edits of HTTP-family cases, where URL, headers and body must stay on the server. */
const editCases = Object.entries(cases).flatMap(([type, contractCase]) =>
    contractCase.edit === undefined
        ? []
        : [{ type, form: contractCase.form, edit: contractCase.edit }],
);

test.each(Object.entries(cases))(
    '%s creation sends exactly the contract payload',
    (_, contractCase) => {
        expect(buildPayload(filled(contractCase.form), null)).toEqual(contractCase.create);
    },
);

test.each(editCases)(
    '$type edit without replacements omits every secret',
    ({ type, form, edit }) => {
        const state = {
            ...filled(form),
            replace_url: false,
            replace_headers: false,
            replace_body: false,
        };

        expect(buildPayload(state, type)).toEqual(edit);
    },
);

test('the contract covers every type and both HTTP-family edits', () => {
    expect(Object.keys(cases).sort()).toEqual(['dns', 'heartbeat', 'http', 'keyword', 'tls']);
    expect(editCases.map(({ type }) => type)).toEqual(['http', 'keyword']);
});
