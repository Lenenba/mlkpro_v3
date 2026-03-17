import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
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
