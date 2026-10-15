/** Verify that quality gates accept valid source and reject real hook, promise and typing mistakes. */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import test from 'node:test';
import { ESLint } from 'eslint';
import * as prettier from 'prettier';

/** Absolute repository path shared by the checked-in configuration and temporary fixtures. */
const projectRoot = resolve(import.meta.dirname, '..');
/** Real ESLint instance loading the same configuration as the developer command. */
const eslint = new ESLint({ cwd: projectRoot });

/**
 * Create a real TypeScript project member for typed linting, then remove it even on failure.
 * @param {string} source Synthetic TSX source that is never part of the application build.
 * @param {(file: string, directory: string) => Promise<void>} assertFixture Assertions using the temporary file and its directory.
 * @returns {Promise<void>} Completion of assertions and fixture cleanup.
 */
async function withFixture(source, assertFixture) {
    /** Temporary directory under the real TS include path, isolated from existing source files. */
    const directory = await mkdtemp(join(projectRoot, 'resources/js/quality-probe-'));
    /** Synthetic module used by both TypeScript and ESLint. */
    const file = join(directory, 'probe.tsx');

    try {
        await writeFile(file, source);
        await assertFixture(file, directory);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
}

/**
 * Run the installed compiler against one fixture with all project strictness options inherited.
 * @param {string} directory Temporary fixture directory containing probe.tsx.
 * @returns {Promise<import('node:child_process').SpawnSyncReturns<string>>} Compiler status and diagnostics without emitted assets.
 */
async function checkTypes(directory) {
    /** Local config narrows inputs without weakening the production project's compiler settings. */
    const configuration = join(directory, 'tsconfig.json');
    await writeFile(
        configuration,
        JSON.stringify({ extends: join(projectRoot, 'tsconfig.json'), include: ['probe.tsx'] }),
    );

    return spawnSync(
        process.execPath,
        [
            join(projectRoot, 'node_modules/typescript/bin/tsc'),
            '--project',
            configuration,
            '--pretty',
            'false',
        ],
        { cwd: projectRoot, encoding: 'utf8', timeout: 30000 },
    );
}

/** Use the real lint and compiler gates on a minimal accessible component. */
async function acceptsTypedReactComponent() {
    await withFixture(
        `/** Render a synthetic counter for quality-tool verification. */
import { useState } from 'react';
/** Keep the hook call unconditional and render a keyboard-operable button. */
export function Counter() {
    /** Current synthetic count and its state updater. */
    const [count, setCount] = useState(0);
    return <button type="button" onClick={() => setCount(count + 1)}>{count}</button>;
}
`,
        /**
         * @param {string} file Fixture source path.
         * @param {string} directory Isolated compiler project directory.
         */
        async (file, directory) => {
            /** Lint results from the same typed configuration used for application modules. */
            const results = await eslint.lintFiles([file]);
            assert.equal(results[0].errorCount, 0, JSON.stringify(results[0].messages));
            /** Compiler result proving that strict settings also accept the valid fixture. */
            const result = await checkTypes(directory);
            assert.equal(result.status, 0, result.stdout + result.stderr);
        },
    );
}

/** Confirm that actual React and typed-promise diagnostics reach the lint result. */
async function rejectsInvalidHooksAndPromises() {
    await withFixture(
        `/** Deliberately invalid source used only to test rejection behavior. */
import { useState } from 'react';
/** Exercise conditional hooks and an unacknowledged detached promise. */
export function Broken({ active }: { active: boolean }) {
    if (active) { useState(0); }
    Promise.resolve('unhandled');
    return <div />;
}
`,
        /** @param {string} file Deliberately invalid fixture source path. */
        async (file) => {
            /** Diagnostics must include both independent behavior checks. */
            const results = await eslint.lintFiles([file]);
            /** Rule identifiers produced by the real ESLint pipeline. */
            const rules = results[0].messages.map(
                /**
                 * @param {import('eslint').Linter.LintMessage} message One lint diagnostic.
                 * @returns {string|null} Identifying rule name.
                 */
                (message) => message.ruleId,
            );
            assert.ok(rules.includes('react-hooks/rules-of-hooks'), JSON.stringify(rules));
            assert.ok(
                rules.includes('@typescript-eslint/no-floating-promises'),
                JSON.stringify(rules),
            );
        },
    );
}

/** Prove that the inherited compiler options reject a subtle optional-value mistake. */
async function rejectsInvalidOptionalValue() {
    await withFixture(
        `/** Deliberately invalid optional property assignment for the compiler gate. */
interface Entry {
    /** An omitted label is allowed; an explicitly undefined label is not. */
    label?: string;
}
/** Invalid synthetic value that must never pass the compiler gate. */
export const entry: Entry = { label: undefined };
`,
        /**
         * @param {string} _file Fixture path unused by this compiler-only assertion.
         * @param {string} directory Local compiler configuration directory.
         */
        async (_file, directory) => {
            /** Expected compiler failure, without accessing a network or generating output files. */
            const result = await checkTypes(directory);
            assert.equal(result.status, 2, result.stdout + result.stderr);
            assert.match(result.stdout, /exactOptionalPropertyTypes/);
        },
    );
}

/** Exercise the same resolved formatting policy as the command-line formatter. */
async function checksFormattingRoundTrip() {
    /** Virtual source path used only to resolve the checked-in formatting configuration. */
    const filepath = join(projectRoot, 'resources/js/probe.tsx');
    /** Formatting options shared with the real CLI, without parsing unrelated source types. */
    const options = { ...(await prettier.resolveConfig(filepath)), filepath };
    /** Intentionally unformatted synthetic code with its documentation preserved. */
    const source = '/** Synthetic format probe. */\nexport const probe={ready:true}\n';
    assert.equal(await prettier.check(source, options), false);
    /** Formatted output should satisfy the check and preserve explanatory comments. */
    const formatted = await prettier.format(source, options);
    assert.equal(await prettier.check(formatted, options), true);
    assert.match(formatted, /Synthetic format probe/);
}

/** Prevent generated output from entering source lint checks as the build grows. */
async function ignoresGeneratedBundles() {
    assert.equal(
        await eslint.isPathIgnored(join(projectRoot, 'public/build/assets/probe.js')),
        true,
    );
}

test('accepts a typed React component with unconditional hooks', acceptsTypedReactComponent);
test('rejects conditional hooks and unhandled promises', rejectsInvalidHooksAndPromises);
test('rejects undefined assignments to exact optional properties', rejectsInvalidOptionalValue);
test('detects formatting drift and accepts the formatted result', checksFormattingRoundTrip);
test('excludes generated browser bundles from linting', ignoresGeneratedBundles);
