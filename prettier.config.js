/** Format frontend code and its configuration consistently with the existing editor settings. */
export default {
    // Indent application TS/JS/CSS by four spaces.
    tabWidth: 4,
    // Match the existing single-quoted JavaScript and TypeScript source.
    singleQuote: true,
    // Terminate statements explicitly.
    semi: true,
    // Keep multiline edits stable when adding the next element or parameter.
    trailingComma: 'all',
    // Prefer readable lines around 100 columns; long strings may remain intact.
    printWidth: 100,
    // Emit LF consistently on every supported host.
    endOfLine: 'lf',
    overrides: [
        {
            // Keep npm and TypeScript manifests at their existing two-space indentation.
            files: '*.json',
            options: { tabWidth: 2 },
        },
    ],
};
