/** Register DOM assertions, clear mounted React trees and reject unintended network access. */
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

/** Reset page metadata and install strict network barriers for every test. */
function prepareDom(): void {
    document.title = '';
    window.history.replaceState(null, '', '/');
    vi.stubGlobal(
        'fetch',
        vi.fn(
            /** Refuse real fetch requests; a test must explicitly provide its response double. */
            (): Promise<never> =>
                Promise.reject(new Error('Unexpected fetch request in a DOM test.')),
        ),
    );
    vi.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(
        /** Refuse Axios/Inertia transport calls unless the specific test intercepts navigation. */
        (): never => {
            throw new Error('Unexpected XMLHttpRequest in a DOM test.');
        },
    );
}

/** Unmount React trees and restore any simulated clocks after each test. */
function cleanDom(): void {
    cleanup();
    vi.useRealTimers();
}

beforeEach(prepareDom);
afterEach(cleanDom);
