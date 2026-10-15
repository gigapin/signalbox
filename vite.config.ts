/** Build the Laravel Inertia entry point with React 19 and Tailwind 4. */
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.tsx'], refresh: true }),
        react(),
        tailwindcss(),
    ],
});
