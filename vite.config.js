import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    server: {
        // Vite 6 only allows localhost origins by default (security fix).
        // Laravel Herd serves the app over HTTPS on a *.test domain, which is a
        // different origin than the Vite dev server (:5173), so we must allow it.
        cors: {
            origin: [
                // Default Vite localhost allowances
                /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                // Laravel Herd / Valet .test domains
                /^https?:\/\/[^/]+\.test(?::\d+)?$/,
            ],
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        // Keep the build stable for SSR-less Inertia pages. Aggressive manual chunking
        // introduced a circular runtime dependency between framework and calendar code.
        chunkSizeWarningLimit: 1600,
        sourcemap: false,
        reportCompressedSize: false,
        minify: 'esbuild',
    },
});
