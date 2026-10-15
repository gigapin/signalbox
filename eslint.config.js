/** Check application TypeScript with type information and JavaScript tooling in its Node environment. */
import js from '@eslint/js';
import { defineConfig, globalIgnores } from 'eslint/config';
import prettier from 'eslint-config-prettier';
import hooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default defineConfig([
    // Never lint dependencies, runtime state or generated bundles.
    globalIgnores([
        'vendor/**',
        'node_modules/**',
        'public/**',
        'storage/**',
        'bootstrap/cache/**',
        '.phpstan-cache/**',
    ]),
    {
        // Keep typed rules inside the TypeScript project's declared source files.
        files: ['resources/js/**/*.{ts,tsx}', 'vite.config.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommendedTypeChecked],
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        rules: {
            // Prefer type-only imports and require explicit acknowledgement of detached promises.
            '@typescript-eslint/consistent-type-imports': 'error',
            '@typescript-eslint/no-floating-promises': 'error',
        },
    },
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        extends: [hooks.configs.flat.recommended],
        languageOptions: { globals: globals.browser },
    },
    {
        // Configuration and quality checks execute in Node, never inside the browser bundle.
        files: ['*.js', 'scripts/**/*.mjs'],
        extends: [js.configs.recommended],
        languageOptions: { globals: globals.node },
    },
    {
        files: ['vite.config.ts'],
        languageOptions: { globals: globals.node },
    },
    // Let Prettier own formatting, without competing style fixes from ESLint.
    prettier,
]);
