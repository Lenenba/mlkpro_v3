import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const manualChunks = (id) => {
    if (!id.includes('node_modules')) {
        return undefined;
    }

    const normalized = id.replace(/\\/g, '/');

    if (
        normalized.includes('/node_modules/vue/') ||
        normalized.includes('/node_modules/@vue/') ||
        normalized.includes('/node_modules/@inertiajs/') ||
        normalized.includes('/node_modules/vue-i18n/')
    ) {
        return 'framework';
    }

    if (
        normalized.includes('/node_modules/preline/') ||
        normalized.includes('/node_modules/@preline/') ||
        normalized.includes('/node_modules/clipboard/')
    ) {
        return 'ui-vendors';
    }

    if (
        normalized.includes('/node_modules/@fullcalendar/') ||
        normalized.includes('/node_modules/vue-full-calendar/') ||
        normalized.includes('/node_modules/dayjs/')
    ) {
        return 'calendar-vendors';
    }

    if (
        normalized.includes('/node_modules/vuedraggable/') ||
        normalized.includes('/node_modules/dropzone/')
    ) {
        return 'interaction-vendors';
    }

    if (
        normalized.includes('/node_modules/apexcharts/')
    ) {
        return 'charts';
    }

    if (
        normalized.includes('/node_modules/axios/') ||
        normalized.includes('/node_modules/lodash/') ||
        normalized.includes('/node_modules/cally/')
    ) {
        return 'shared-vendors';
    }

    return 'vendor';
};

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
        chunkSizeWarningLimit: 1000, // Augmente la limite de 500 kB a 1000 kB
        sourcemap: false,
        reportCompressedSize: false,
        minify: 'esbuild',
        rollupOptions: {
            output: {
                manualChunks,
            },
        },
    },
});
