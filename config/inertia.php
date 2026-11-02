<?php

/**
 * Resolve React pages from one directory; public metadata and essential status content are rendered by Blade.
 */

return [
    // React SSR remains optional: Blade provides crawler-readable status documents without a Node rendering service.
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
