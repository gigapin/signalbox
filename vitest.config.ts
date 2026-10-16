/** Run React DOM tests separately from the Laravel asset pipeline and Node quality-tool tests. */
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    test: {
        // Import assertions explicitly, keeping test globals out of production TypeScript.
        globals: false,
        environment: 'jsdom',
        environmentOptions: { jsdom: { url: 'http://127.0.0.1:8000/' } },
        include: ['tests/frontend/**/*.test.{ts,tsx}'],
        setupFiles: ['tests/frontend/setup.ts'],
        // A deterministic single worker avoids sharing Inertia's singleton router across simultaneous files.
        fileParallelism: false,
        maxWorkers: 1,
        restoreMocks: true,
        clearMocks: true,
        unstubGlobals: true,
    },
});
