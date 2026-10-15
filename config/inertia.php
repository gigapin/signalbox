<?php

/**
 * Resolve React pages from one directory and disable optional SSR during bootstrap.
 */

return [
    // Render the initial document in the browser until an SSR service is introduced.
    'ssr' => ['enabled' => false],
    // Fail immediately if a controller references a React page missing from disk.
    'ensure_pages_exist' => true,
    // Match the lowercase directory used by the Vite page-module glob.
    'page_paths' => [resource_path('js/pages')],
    // Only TypeScript React components are valid application pages.
    'page_extensions' => ['tsx'],
    // Apply the same filesystem checks to Inertia test assertions.
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['tsx'],
    ],
];
